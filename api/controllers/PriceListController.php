<?php
// /api/controllers/PriceListController.php

require_once __DIR__ . '/../models/PriceList.php';

class PriceListController {
    private $conn;
    private $priceListModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->priceListModel = new PriceList($db);
    }

    public function get() {
        // Correct method name from router is 'get_price_list_data'
        $this->getPriceListData();
    }
    
    public function getPriceListData() {
        // Permission check is handled by the router
        try {
            $result = $this->priceListModel->getFullPriceList();
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت لیست قیمت: ' . $e->getMessage()], 500);
        }
    }

    public function updatePricesByPercentage() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['ids']) || !isset($data['percentage']) || empty($data['type'])) {
                send_json(['error' => 'پارامترهای ناقص'], 400);
                return;
            }
            
            $result = $this->priceListModel->updatePricesByPercentage(
                $data['ids'],
                $data['type'],
                $data['percentage']
            );
            
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function setPrice() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['id']) || !isset($data['price']) || empty($data['type'])) {
                send_json(['error' => 'پارامترهای ناقص'], 400);
                return;
            }
            
            $result = $this->priceListModel->setPrice(
                $data['id'],
                $data['type'],
                $data['price']
            );
            
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
}