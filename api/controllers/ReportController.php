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
        // Permission check is handled by the router
        try {
            $result = $this->reportModel->getProfitLossReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getInvoicesReport($data) {
        // Permission check is handled by the router
        try {
            $result = $this->reportModel->getInvoicesReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getDeliveryReport($data) {
        try {
            $result = $this->reportModel->getDeliveryReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
    
    public function getInventoryLedgerReport($data) {
        // Permission check is handled by the router
        try {
            $productId = intval($data['productId'] ?? 0);
            if (!$productId) { 
                send_json(['error' => 'شناسه محصول نامعتبر است.'], 400); 
                return; 
            }
            $result = $this->reportModel->getInventoryLedgerReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getPersonStatement($data) {
        // Permission check is handled by the router
        try {
            if (empty($data['personType']) || !in_array($data['personType'], ['customer', 'supplier', 'partner', 'contact']) || empty($data['personId']) || !is_numeric($data['personId'])) {
                send_json(['error' => 'اطلاعات شخص نامعتبر است.'], 400);
                return;
            }
            $result = $this->reportModel->getPersonStatement($data);
            if (isset($result['error'])) {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 404);
            } else {
                send_json($result);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
    
    public function getAccountStatement($data) {
        // Permission check is handled by the router
        try {
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
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
    
    public function getExpensesReport($data) {
        // Permission check is handled by the router
        try {
            $result = $this->reportModel->getExpensesReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getIncomesReport($data) {
        try {
            $result = $this->reportModel->getIncomesReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getInventoryReport($data) {
        // Permission check is handled by the router
        try {
            $result = $this->reportModel->getInventoryReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
    
    public function getInventoryValueReport($data) {
        // Permission check is handled by the router
        try {
            $result = $this->reportModel->getInventoryValueReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getCogsProfitReport($data) {
        // Permission check is handled by the router
        try {
            $result = $this->reportModel->getCogsProfitReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getAgedReceivablesReport($data) {
        try {
            $result = $this->reportModel->getAgedReceivablesReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getCashFlowReport($data) {
        try {
            $result = $this->reportModel->getCashFlowReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getPartnerProfitReport($data) {
        try {
            $result = $this->reportModel->getPartnerProfitReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getCustomReport($data) {
        try {
            $result = $this->reportModel->getCustomReport($data);
            $status = intval($result['statusCode'] ?? 200);
            unset($result['statusCode']);
            send_json($result, $status >= 400 ? $status : 200);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getChecksReport($data) {
        try {
            $result = $this->reportModel->getChecksReport($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function exportReport($data) { // This parameter is for POST, but we will use $_GET
        // Permission check is handled by the router
        try {
            // START FIX: Read parameters from $_GET for CSV export
            $params = $_GET;
            $params['entityId'] = report_entity_id($params);
            $reportType = $params['reportType'] ?? '';
            // END FIX
            
            $csvData = $this->reportModel->getReportForExport($reportType, $params);
            
            if (isset($csvData['error'])) {
                // We cannot send JSON here as it's a file download
                header("Content-Type: text/plain; charset=utf-8");
                http_response_code(400); // Bad Request
                die("Error generating report: " . $csvData['error']);
            }
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $csvData['filename'] . '"');
            
            // Add BOM for Excel to recognize UTF-8
            echo "\xEF\xBB\xBF";

            $output = fopen('php://output', 'w');
            fputcsv($output, $csvData['headers']);
            foreach ($csvData['rows'] as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
            exit();
        } catch (Exception $e) {
            header("Content-Type: text/plain; charset=utf-8");
            http_response_code(500); // Internal Server Error
            die("An unexpected error occurred: " . $e->getMessage());
        }
    }
}