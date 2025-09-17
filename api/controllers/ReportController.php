<?php
// /api/controllers/ReportController.php

require_once __DIR__ . '/../models/ReportModel.php';

class ReportController {
    private $conn;
    private $reportModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->reportModel = new ReportModel($db);
    }

    public function getProfitLossReport($data) {
        $result = $this->reportModel->getProfitLossReport($data);
        send_json($result);
    }

    public function getInvoicesReport($data) {
        $result = $this->reportModel->getInvoicesReport($data);
        send_json($result);
    }
    
    public function getInventoryLedgerReport($data) {
        $productId = intval($data['productId'] ?? 0);
        if (!$productId) { 
            send_json(['error' => 'شناسه محصول نامعتبر است.'], 400); 
            return; 
        }
        $result = $this->reportModel->getInventoryLedgerReport($data);
        send_json($result);
    }

    public function getPersonStatement($data) {
        if (empty($data['personType']) || empty($data['personId']) || $data['personId'] <= 0) {
            send_json(['error' => 'اطلاعات شخص نامعتبر است.'], 400);
            return;
        }
        $result = $this->reportModel->getPersonStatement($data);
        if (isset($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 404);
        } else {
            send_json($result);
        }
    }
    
    public function getAccountStatement($data) {
        $accountId = intval($data['accountId'] ?? 0); 
        if (!$accountId) { 
            send_json(['error' => 'شناسه حساب نامعتبر است.'], 400); 
            return; 
        }
        $result = $this->reportModel->getAccountStatement($data);
        if (isset($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 404);
        } else {
            send_json($result);
        }
    }
    
    public function getExpensesReport($data) {
        $result = $this->reportModel->getExpensesReport($data);
        send_json($result);
    }

    public function getInventoryReport($data) {
        $result = $this->reportModel->getInventoryReport($data);
        send_json($result);
    }
    
    public function getInventoryValueReport($data) {
        $result = $this->reportModel->getInventoryValueReport($data);
        send_json($result);
    }

    public function getCogsProfitReport($data) {
        $result = $this->reportModel->getCogsProfitReport($data);
        send_json($result);
    }

    /**
     * Handles exporting report data to CSV format.
     * Note: This method receives data via $_GET.
     */
    public function exportReport($data) {
        // Use current entity from session for security, overriding any GET parameter
        $data['entityId'] = $_SESSION['current_entity_id'];
        $reportType = $data['reportType'] ?? '';
        
        $csvData = $this->reportModel->getReportForExport($reportType, $data);
        
        if (isset($csvData['error'])) {
            // Handle error, maybe show an HTML error page.
            die($csvData['error']);
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $csvData['filename']);
        
        // Add UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'w');
        fputcsv($output, $csvData['headers']);
        foreach ($csvData['rows'] as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit();
    }
}