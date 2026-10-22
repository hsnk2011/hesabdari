<?php
// /api/controllers/SettingsController.php

require_once __DIR__ . '/../models/Settings.php';
require_once __DIR__ . '/../models/EntitySettings.php';
require_once __DIR__ . '/../core/MailService.php';

class SettingsController {
    private $conn;
    private $settingsModel;
    private $entitySettingsModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->settingsModel = new Settings($db);
        $this->entitySettingsModel = new EntitySettings($db);
    }

    public function switchEntity($data) {
        try {
            $entityId = intval($data['entity_id'] ?? 0);
            if ($entityId > 0) {
                $allowed_entities = $_SESSION['allowed_entities'] ?? [];
                if (!in_array($entityId, array_map('intval', $allowed_entities), true)) {
                    send_json(['error' => 'شما به این مجموعه تجاری دسترسی ندارید.'], 403);
                    return;
                }

                $stmt = $this->conn->prepare("SELECT id FROM business_entities WHERE id = ?");
                $stmt->bind_param("i", $entityId);
                $stmt->execute();
                $result = db_stmt_to_assoc_array($stmt);

                if (!empty($result)) {
                    $_SESSION['current_entity_id'] = $entityId;
                    log_activity($this->conn, 'SWITCH_ENTITY', "کاربر مجموعه فعال را به شناسه {$entityId} تغییر داد.");
                    generate_csrf_token();
                    send_json(['success' => true, 'new_entity_id' => $entityId, 'csrf_token' => $_SESSION['csrf_token']]);
                } else {
                    send_json(['error' => 'مجموعه تجاری نامعتبر است.'], 404);
                }
            } else {
                send_json(['error' => 'شناسه مجموعه تجاری نامعتبر است.'], 400);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getAppSettings() {
        // Permission check is handled by the router
        try {
            $settings = $this->settingsModel->getAllSettings();
            unset($settings['vapid_private_pem']); // Web Push signing key never leaves the server
            $entities_res = $this->conn->query("SELECT id, name FROM business_entities ORDER BY id ASC");
            
            $entities = [];
            if ($entities_res) {
                while ($row = $entities_res->fetch_assoc()) {
                    $entities[] = $row;
                }
            }

            send_json([
                'settings' => $settings,
                'entities' => $entities
            ]);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
    
    private function normalizeEntityName($name) {
        $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
            return null;
        }
        return $name;
    }

    private function entityNameExists($name, $excludeId = 0) {
        $stmt = $this->conn->prepare("SELECT id FROM business_entities WHERE name = ? AND id <> ? LIMIT 1");
        $stmt->bind_param("si", $name, $excludeId);
        $stmt->execute();
        return !empty(db_stmt_to_assoc_array($stmt));
    }

    private function listEntities() {
        $entities = [];
        $res = $this->conn->query("SELECT id, name FROM business_entities ORDER BY id ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $entities[] = $row;
            }
        }
        return $entities;
    }

    public function createEntity($data) {
        try {
            $name = $this->normalizeEntityName($data['name'] ?? '');
            if ($name === null) {
                send_json(['error' => 'نام مجموعه معتبر نیست (۱ تا ۲۵۵ کاراکتر).'], 400);
                return;
            }
            if ($this->entityNameExists($name)) {
                send_json(['error' => 'مجموعه‌ای با این نام قبلاً ثبت شده است.'], 409);
                return;
            }

            $userId = intval($_SESSION['user_id'] ?? 0);
            $this->conn->begin_transaction();
            try {
                $stmt = $this->conn->prepare("INSERT INTO business_entities (name) VALUES (?)");
                $stmt->bind_param("s", $name);
                $stmt->execute();
                $newId = (int)$this->conn->insert_id;
                $stmt->close();

                // The creator gets access right away, otherwise the new entity would be invisible to them.
                if ($userId > 0) {
                    $acc = $this->conn->prepare("INSERT IGNORE INTO user_entity_access (user_id, entity_id) VALUES (?, ?)");
                    $acc->bind_param("ii", $userId, $newId);
                    $acc->execute();
                    $acc->close();
                }
                $this->conn->commit();
            } catch (Exception $e) {
                $this->conn->rollback();
                throw $e;
            }

            $allowed = array_map('intval', $_SESSION['allowed_entities'] ?? []);
            if ($userId > 0 && !in_array($newId, $allowed, true)) {
                $allowed[] = $newId;
            }
            $_SESSION['allowed_entities'] = $allowed;

            log_activity($this->conn, 'CREATE_ENTITY', "مجموعه جدید «{$name}» با شناسه {$newId} ایجاد شد.");
            send_json([
                'success' => true,
                'entity' => ['id' => $newId, 'name' => $name],
                'entities' => $this->listEntities(),
                'allowed_entities' => $allowed
            ]);
        } catch (Exception $e) {
            send_json(['error' => 'خطا در ایجاد مجموعه: ' . $e->getMessage()], 500);
        }
    }

    public function updateEntity($data) {
        try {
            $id = intval($data['id'] ?? 0);
            $name = $this->normalizeEntityName($data['name'] ?? '');
            if ($id <= 0 || $name === null) {
                send_json(['error' => 'اطلاعات مجموعه نامعتبر است.'], 400);
                return;
            }
            if ($this->entityNameExists($name, $id)) {
                send_json(['error' => 'مجموعه دیگری با این نام وجود دارد.'], 409);
                return;
            }
            $stmt = $this->conn->prepare("UPDATE business_entities SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $id);
            $stmt->execute();
            $stmt->close();

            $chk = $this->conn->prepare("SELECT id FROM business_entities WHERE id = ?");
            $chk->bind_param("i", $id);
            $chk->execute();
            $exists = !empty(db_stmt_to_assoc_array($chk));
            if (!$exists) {
                send_json(['error' => 'مجموعه یافت نشد.'], 404);
                return;
            }

            log_activity($this->conn, 'UPDATE_ENTITY', "نام مجموعه {$id} به «{$name}» تغییر کرد.");
            send_json(['success' => true, 'entities' => $this->listEntities()]);
        } catch (Exception $e) {
            send_json(['error' => 'خطا در ویرایش مجموعه: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Counts rows owned by an entity in every table having an entity_id column.
     * Tables that only hold per-entity configuration are ignored (they are removed with the entity).
     */
    private function countEntityData($entityId) {
        $ignored = ['user_entity_access', 'entity_settings'];
        $tables = [];
        $res = $this->conn->query(
            "SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
             WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'entity_id'"
        );
        while ($res && ($row = $res->fetch_row())) {
            if (!in_array($row[0], $ignored, true)) {
                $tables[] = $row[0];
            }
        }

        $used = [];
        foreach ($tables as $table) {
            $stmt = $this->conn->prepare("SELECT COUNT(*) FROM `{$table}` WHERE entity_id = ?");
            $stmt->bind_param("i", $entityId);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            if ((int)$count > 0) {
                $used[$table] = (int)$count;
            }
        }
        return $used;
    }

    public function deleteEntity($data) {
        try {
            $id = intval($data['id'] ?? 0);
            if ($id <= 0) {
                send_json(['error' => 'شناسه مجموعه نامعتبر است.'], 400);
                return;
            }
            $defaultId = defined('DEFAULT_ENTITY_ID') ? (int)DEFAULT_ENTITY_ID : 1;
            if ($id === $defaultId) {
                send_json(['error' => 'مجموعه پیش‌فرض قابل حذف نیست.'], 400);
                return;
            }
            if ($id === intval($_SESSION['current_entity_id'] ?? 0)) {
                send_json(['error' => 'مجموعه فعال فعلی قابل حذف نیست. ابتدا به مجموعه دیگری بروید.'], 400);
                return;
            }

            $stmt = $this->conn->prepare("SELECT name FROM business_entities WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            if (empty($rows)) {
                send_json(['error' => 'مجموعه یافت نشد.'], 404);
                return;
            }
            $name = $rows[0]['name'];

            // Foreign keys cascade on many tables, so refuse to delete anything that holds real data.
            $used = $this->countEntityData($id);
            if (!empty($used)) {
                send_json([
                    'error' => 'این مجموعه دارای اطلاعات ثبت‌شده است و حذف نمی‌شود. ابتدا اطلاعات آن را منتقل یا حذف کنید.',
                    'details' => $used
                ], 409);
                return;
            }

            $this->conn->begin_transaction();
            try {
                foreach (['entity_settings', 'user_entity_access', 'business_entities'] as $table) {
                    $col = ($table === 'business_entities') ? 'id' : 'entity_id';
                    $del = $this->conn->prepare("DELETE FROM `{$table}` WHERE `{$col}` = ?");
                    $del->bind_param("i", $id);
                    $del->execute();
                    $del->close();
                }
                $this->conn->commit();
            } catch (Exception $e) {
                $this->conn->rollback();
                throw $e;
            }

            $_SESSION['allowed_entities'] = array_values(array_filter(
                array_map('intval', $_SESSION['allowed_entities'] ?? []),
                function ($v) use ($id) { return $v !== $id; }
            ));

            log_activity($this->conn, 'DELETE_ENTITY', "مجموعه «{$name}» (شناسه {$id}) حذف شد.");
            send_json([
                'success' => true,
                'entities' => $this->listEntities(),
                'allowed_entities' => $_SESSION['allowed_entities']
            ]);
        } catch (Exception $e) {
            send_json(['error' => 'خطا در حذف مجموعه: ' . $e->getMessage()], 500);
        }
    }

    public function getPublicSettings() {
        // No permission check needed for public settings
        try {
            $settings = $this->settingsModel->getPublicSettings();
            send_json($settings);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function saveAppSettings($data) {
        // Permission check is handled by the router
        try {
            if (!isset($data['settings']) || !is_array($data['settings'])) {
                send_json(['error' => 'اطلاعات تنظیمات نامعتبر است.'], 400);
                return;
            }

            $this->conn->begin_transaction();
            try {
                if (!empty($data['settings'])) {
                    $this->settingsModel->saveAllSettings($data['settings']);
                }

                if (isset($data['entities']) && is_array($data['entities'])) {
                    $stmt = $this->conn->prepare("UPDATE business_entities SET name = ? WHERE id = ?");
                    foreach ($data['entities'] as $entity) {
                        if (isset($entity['id']) && isset($entity['name']) && trim($entity['name']) !== '') {
                            $entityName = trim($entity['name']);
                            $entityId = intval($entity['id']);
                            $stmt->bind_param("si", $entityName, $entityId);
                            $stmt->execute();
                        }
                    }
                    $stmt->close();
                }
                
                $this->conn->commit();
                // workspace_enabled changes the permission list: re-read it on this user's next request.
                $_SESSION['access_checked_at'] = 0;
                log_activity($this->conn, 'SAVE_SETTINGS', 'تنظیمات برنامه بروزرسانی شد.');
                send_json(['success' => true]);

            } catch (Exception $e) {
                $this->conn->rollback();
                throw $e; // Re-throw to be caught by the outer catch block
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطا در ذخیره سازی تنظیمات: ' . $e->getMessage()], 500);
        }
    }

    public function getEntityPrintSettings() {
        try {
            if (!isset($_SESSION['user_id'])) {
                send_json(['error' => 'ابتدا وارد شوید.'], 401);
                return;
            }
            $entityId = intval($_SESSION['current_entity_id'] ?? 0);
            if ($entityId <= 0) {
                send_json(['error' => 'مجموعه تجاری انتخاب نشده است.'], 400);
                return;
            }
            $settings = $this->entitySettingsModel->getEntityPrintSettings($entityId);
            send_json($settings);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function saveEntityPrintSettings($data) {
        try {
            if (!isset($_SESSION['user_id'])) {
                send_json(['error' => 'ابتدا وارد شوید.'], 401);
                return;
            }
            if (!isset($data['settings']) || !is_array($data['settings'])) {
                send_json(['error' => 'اطلاعات تنظیمات نامعتبر است.'], 400);
                return;
            }
            $entityId = intval($_SESSION['current_entity_id'] ?? 0);
            if ($entityId <= 0) {
                send_json(['error' => 'مجموعه تجاری انتخاب نشده است.'], 400);
                return;
            }
            
            // Check if user has access to this entity
            $allowed_entities = $_SESSION['allowed_entities'] ?? [];
            if (!in_array($entityId, $allowed_entities)) {
                send_json(['error' => 'شما به این مجموعه تجاری دسترسی ندارید.'], 403);
                return;
            }
            
            $ok = $this->entitySettingsModel->saveEntityPrintSettings($entityId, $data['settings']);
            if ($ok) {
                log_activity($this->conn, 'SAVE_ENTITY_PRINT_SETTINGS', "تنظیمات چاپ مجموعه {$entityId} بروزرسانی شد.");
                send_json(['success' => true]);
            } else {
                send_json(['error' => 'ذخیره تنظیمات انجام نشد.'], 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطا در ذخیره تنظیمات: ' . $e->getMessage()], 500);
        }
    }

    public function testEmailSettings($data = null) {
        check_permission('manage_settings');
        $to = trim((string)($data['to'] ?? ''));
        $result = MailService::sendTest($this->conn, $to !== '' ? $to : null);
        if (!empty($result['success'])) {
            send_json($result);
            return;
        }
        send_json(['error' => $result['error'] ?? 'ارسال ایمیل آزمایشی ناموفق بود.'], 400);
    }
}