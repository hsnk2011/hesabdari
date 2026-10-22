<?php
// /api/controllers/AssetController.php
// دارایی‌ها — register, purchase/sale documents, tgju prices.
// Permission manage_assets is checked by the router.
require_once __DIR__ . '/../models/Asset.php';
require_once __DIR__ . '/../core/AssetPriceService.php';

class AssetController {
    private $conn;
    private $model;

    public function __construct($db) {
        $this->conn = $db;
        $this->model = new Asset($db);
    }

    private function respond($result) {
        if (!empty($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 400);
        }
        send_json($result);
    }

    private function guarded(callable $fn) {
        try {
            $this->respond($fn());
        } catch (Exception $e) {
            $code = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? (int)$e->getCode() : 500;
            send_json(['error' => $e->getMessage()], $code);
        }
    }

    public function listAssets($data) {
        $this->guarded(function () use ($data) {
            $rows = $this->model->listAssets(!empty($data['include_archived']));
            $categories = Asset::DEFAULT_CATEGORIES;
            foreach ($rows as $r) {
                if (!empty($r['category']) && !in_array($r['category'], $categories, true)) {
                    $categories[] = $r['category'];
                }
            }
            return [
                'success' => true,
                'assets' => $rows,
                'categories' => $categories,
                'prices' => (new AssetPriceService($this->conn))->getPrices(),
            ];
        });
    }

    public function saveAsset($data) {
        $this->guarded(function () use ($data) { return $this->model->saveAsset($data); });
    }

    public function deleteAsset($data) {
        $this->guarded(function () use ($data) { return $this->model->deleteAsset($data['id'] ?? 0); });
    }

    public function getHistory($data) {
        $this->guarded(function () use ($data) { return $this->model->getHistory($data['id'] ?? 0); });
    }

    public function listDocuments($data) {
        $this->guarded(function () use ($data) { return $this->model->listDocuments($data); });
    }

    public function getDocument($data) {
        $this->guarded(function () use ($data) { return $this->model->getDocument($data['id'] ?? 0); });
    }

    public function saveDocument($data) {
        $this->guarded(function () use ($data) { return $this->model->saveDocument($data); });
    }

    public function deleteDocument($data) {
        $this->guarded(function () use ($data) { return $this->model->deleteDocument($data['id'] ?? 0); });
    }

    public function getPrices($data) {
        $this->guarded(function () {
            return ['success' => true, 'prices' => (new AssetPriceService($this->conn))->getPrices()];
        });
    }

    public function refreshPrices($data) {
        $this->guarded(function () {
            $svc = new AssetPriceService($this->conn);
            $res = $svc->refresh(false);
            return ['success' => true, 'updated' => $res['updated'], 'errors' => $res['errors'], 'prices' => $svc->getPrices()];
        });
    }

    public function listConvertible($data) {
        $this->guarded(function () use ($data) { return $this->model->listConvertibleInvoices($data['search'] ?? ''); });
    }

    public function previewConversion($data) {
        $this->guarded(function () use ($data) {
            $id = !empty($data['invoice_id']) ? (int)$data['invoice_id'] : $this->model->resolvePurchaseInvoiceRef($data['invoice_ref'] ?? '');
            return $this->model->previewPurchaseInvoiceConversion($id);
        });
    }

    public function convertPurchaseInvoice($data) {
        $this->guarded(function () use ($data) {
            return $this->model->convertPurchaseInvoice($data['invoice_id'] ?? 0, (array)($data['rows'] ?? []));
        });
    }
}
