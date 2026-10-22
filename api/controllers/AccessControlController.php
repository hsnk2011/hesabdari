<?php
// /api/controllers/AccessControlController.php

require_once __DIR__ . '/../models/Role.php';
require_once __DIR__ . '/../models/User.php';

class AccessControlController {
    private $conn;
    private $roleModel;
    private $userModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->roleModel = new Role($db);
        $this->userModel = new User($db);
    }

    public function getRolesAndPermissions($data) {
        $roles = $this->roleModel->getAllRolesWithPermissions();
        $permissions = $this->roleModel->getAllPermissions();
        send_json(['roles' => $roles, 'permissions' => $permissions]);
    }

    public function saveRole($data) {
        $result = $this->roleModel->saveRole($data);
        if (isset($result['success'])) {
            if (isset($_SESSION['user_id'])) {
                $access_data = $this->userModel->getUserAccessData($_SESSION['user_id']);
                $_SESSION['permissions'] = $access_data['permissions'];
                $_SESSION['allowed_entities'] = $access_data['entities'];
                $current_entity = $_SESSION['current_entity_id'] ?? null;
                $entities = $_SESSION['allowed_entities'] ?? [];
                if (empty($entities)) {
                    $_SESSION['current_entity_id'] = 0;
                } elseif ($current_entity === null || !in_array($current_entity, $entities)) {
                    $_SESSION['current_entity_id'] = $entities[0];
                }
            }
            log_activity($this->conn, 'SAVE_ROLE', "نقش «{$data['role_name']}» ذخیره شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    public function deleteRole($data) {
        $id = $data['id'] ?? null;
        $result = $this->roleModel->deleteRole($id);
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_ROLE', "نقش با شناسه {$id} حذف شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    public function getUsersWithRoles($data) {
        $users = $this->userModel->getAllUsersWithAccess();
        $roles = $this->roleModel->getAllRoles();
        $entities_res = $this->conn->query("SELECT id, name FROM business_entities ORDER BY id ASC");
        
        $entities = [];
        if ($entities_res) {
            while ($row = $entities_res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $entities[] = $row;
            }
        }

        send_json(['users' => $users, 'roles' => $roles, 'entities' => $entities]);
    }
    
    public function updateUserAccess($data) {
        $result = $this->userModel->updateUserAccess($data);
        if (isset($result['success'])) {
            $updatedUserId = intval($data['userId'] ?? 0);
            if (isset($_SESSION['user_id']) && $updatedUserId === intval($_SESSION['user_id'])) {
                $access_data = $this->userModel->getUserAccessData($_SESSION['user_id']);
                $_SESSION['permissions'] = $access_data['permissions'];
                $_SESSION['allowed_entities'] = $access_data['entities'];
                $current_entity = $_SESSION['current_entity_id'] ?? null;
                $entities = $_SESSION['allowed_entities'] ?? [];
                if (empty($entities)) {
                    $_SESSION['current_entity_id'] = 0;
                } elseif ($current_entity === null || !in_array($current_entity, $entities)) {
                    $_SESSION['current_entity_id'] = $entities[0];
                }
            }
            log_activity($this->conn, 'UPDATE_USER_ACCESS', "دسترسی‌های کاربر با شناسه {$data['userId']} بروزرسانی شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
}