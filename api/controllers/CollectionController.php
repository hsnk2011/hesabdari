<?php
// /api/controllers/CollectionController.php
require_once __DIR__ . '/../models/Collection.php';

class CollectionController {
    private $conn;
    private $collectionModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->collectionModel = new Collection($db);
    }

    public function getAttributeTypes() {
        // Permission check is handled by the router
        try {
            $result = $this->collectionModel->getAttributeTypes();
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function saveAttributeType($data) {
        // Permission check is handled by the router
        try {
            if (empty(trim($data['name']))) {
                send_json(['error' => 'نام مشخصه الزامی است.'], 400);
                return;
            }

            $result = $this->collectionModel->saveAttributeType($data);
            if (isset($result['success'])) {
                log_activity($this->conn, 'SAVE_ATTRIBUTE_TYPE', "نوع مشخصه «{$data['name']}» ذخیره شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function deleteAttributeType($data) {
        // Permission check is handled by the router
        try {
            $id = $data['id'] ?? null;
            $result = $this->collectionModel->deleteAttributeType($id);
            if (isset($result['success'])) {
                log_activity($this->conn, 'DELETE_ATTRIBUTE_TYPE', "نوع مشخصه با شناسه {$id} حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getCollections() {
        // Permission check is handled by the router
        try {
            $result = $this->collectionModel->getAllCollectionsWithDetails();
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getFullList($data) { // CORRECTED: Renamed from getFullCollectionsList
        // Permission check is handled by the router
        try {
            $result = $this->collectionModel->getAllCollectionsSimple();
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function saveCollection($data) {
        // Permission check is handled by the router
        try {
            if (empty(trim($data['name']))) {
                send_json(['error' => 'نام کلکسیون الزامی است.'], 400);
                return;
            }
            
            $result = $this->collectionModel->saveCollection($data);
            if (isset($result['success'])) {
                log_activity($this->conn, 'SAVE_COLLECTION', "کلکسیون «{$data['name']}» با شناسه {$result['id']} ذخیره شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function deleteCollection($data) {
        // Permission check is handled by the router
        try {
            $id = $data['id'] ?? null;
            $result = $this->collectionModel->deleteCollection($id);
            if (isset($result['success'])) {
                log_activity($this->conn, 'DELETE_COLLECTION', "کلکسیون با شناسه {$id} حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getCollectionProducts($data) {
        // Permission check is handled by the router
        try {
            $collectionId = $data['collectionId'] ?? null;
            if (!$collectionId) {
                send_json(['error' => 'شناسه کلکسیون الزامی است.'], 400);
                return;
            }
            $result = $this->collectionModel->getCollectionProducts($collectionId);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getAllProductsForSelection($data) {
        // Permission check is handled by the router
        try {
            $result = $this->collectionModel->getAllProductsForSelection();
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function addProductsToCollection($data) {
        // Permission check is handled by the router
        try {
            $collectionId = $data['collectionId'] ?? null;
            $productIds = $data['productIds'] ?? [];
            
            if (!$collectionId) {
                send_json(['error' => 'شناسه کلکسیون الزامی است.'], 400);
                return;
            }

            $result = $this->collectionModel->addProductsToCollection($collectionId, $productIds);
            if (isset($result['success'])) {
                log_activity($this->conn, 'ADD_PRODUCTS_TO_COLLECTION', "تعداد " . count($productIds) . " محصول به کلکسیون {$collectionId} اضافه شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function removeProductsFromCollection($data) {
        // Permission check is handled by the router
        try {
            $productIds = $data['productIds'] ?? [];
            
            if (empty($productIds)) {
                send_json(['error' => 'لیست محصولات الزامی است.'], 400);
                return;
            }

            $result = $this->collectionModel->removeProductsFromCollection($productIds);
            if (isset($result['success'])) {
                log_activity($this->conn, 'REMOVE_PRODUCTS_FROM_COLLECTION', "تعداد " . count($productIds) . " محصول از کلکسیون حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
}