<?php
// /api/controllers/SettingsController.php

require_once __DIR__ . '/../models/Settings.php';

class SettingsController {
    private $conn;
    private $settingsModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->settingsModel = new Settings($db);
    }

    public function switchEntity($data) {
        $entityId = intval($data['entity_id'] ?? 0);
        if ($entityId > 0) {
            // Check if entity exists
            $stmt = $this->conn->prepare("SELECT id FROM business_entities WHERE id = ?");
            $stmt->bind_param("i", $entityId);
            $stmt->execute();
            $result = db_stmt_to_assoc_array($stmt);

            if (!empty($result)) {
                $_SESSION['current_entity_id'] = $entityId;
                log_activity($this->conn, 'SWITCH_ENTITY', "کاربر مجموعه فعال را به شناسه {$entityId} تغییر داد.");
                send_json(['success' => true, 'new_entity_id' => $entityId]);
            } else {
                send_json(['error' => 'مجموعه تجاری نامعتبر است.'], 404);
            }
        } else {
            send_json(['error' => 'شناسه مجموعه تجاری نامعتبر است.'], 400);
        }
    }

    public function getAppSettings() {
        $settings = $this->settingsModel->getAllSettings();
        $entities_res = $this->conn->query("SELECT id, name FROM business_entities ORDER BY id ASC");
        $entities = $entities_res ? $entities_res->fetch_all(MYSQLI_ASSOC) : [];

        send_json([
            'settings' => $settings,
            'entities' => $entities
        ]);
    }

    public function saveAppSettings($data) {
        if (!isset($data['settings']) || !is_array($data['settings'])) {
            send_json(['error' => 'اطلاعات تنظیمات نامعتبر است.'], 400);
            return;
        }

        $this->conn->begin_transaction();
        try {
            // Save general settings
            if (!empty($data['settings'])) {
                $this->settingsModel->saveAllSettings($data['settings']);
            }

            // Save business entity names
            if (isset($data['entities']) && is_array($data['entities'])) {
                $stmt = $this->conn->prepare("UPDATE business_entities SET name = ? WHERE id = ?");
                foreach ($data['entities'] as $entity) {
                    if (isset($entity['id']) && isset($entity['name'])) {
                        $stmt->bind_param("si", $entity['name'], $entity['id']);
                        $stmt->execute();
                    }
                }
                $stmt->close();
            }
            
            $this->conn->commit();
            log_activity($this->conn, 'SAVE_SETTINGS', 'تنظیمات برنامه بروزرسانی شد.');
            send_json(['success' => true]);

        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => 'خطا در ذخیره سازی تنظیمات: ' . $e->getMessage()], 500);
        }
    }
}