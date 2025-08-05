<?php
// /api/controllers/InvoiceController.php

require_once __DIR__ . '/../models/Invoice.php';

/**
 * The InvoiceController handles all requests related to sales and purchase invoices.
 */
class InvoiceController {
    private $conn;
    private $invoiceModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->invoiceModel = new Invoice($db);
    }

    /**
     * Handles saving a sales invoice.
     * @param array $data The invoice data from the client.
     */
    public function saveSalesInvoice($data) {
        $result = $this->invoiceModel->saveSalesInvoice($data);
        if (isset($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles deleting a sales invoice.
     * @param array $data Contains the 'id' of the invoice to delete.
     */
    public function deleteSalesInvoice($data) {
        $id = $data['id'] ?? null;
        $result = $this->invoiceModel->deleteSalesInvoice($id);
        if (isset($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles saving a purchase invoice.
     * @param array $data The invoice data from the client.
     */
    public function savePurchaseInvoice($data) {
        $result = $this->invoiceModel->savePurchaseInvoice($data);
        if (isset($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles deleting a purchase invoice.
     * @param array $data Contains the 'id' of the invoice to delete.
     */
    public function deletePurchaseInvoice($data) {
        $id = $data['id'] ?? null;
        $result = $this->invoiceModel->deletePurchaseInvoice($id);
        if (isset($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles marking an invoice as consignment.
     * @param array $data Contains 'type' ('sales' or 'purchase') and 'id'.
     */
    public function markAsConsignment($data) {
        $result = $this->invoiceModel->updateConsignmentStatus('mark_as_consignment', $data);
        if (isset($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
    
    /**
     * Handles returning an invoice from consignment.
     * @param array $data Contains 'type' ('sales' or 'purchase') and 'id'.
     */
    public function returnFromConsignment($data) {
        $result = $this->invoiceModel->updateConsignmentStatus('return_from_consignment', $data);
        if (isset($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
}