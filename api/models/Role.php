<?php
// /api/models/Role.php
require_once __DIR__ . '/BaseModel.php';

class Role extends BaseModel {
    protected $tableName = 'roles';
    protected $hasEntityId = false;
    
    public function getAllPermissions() {
        $stmt = $this->conn->prepare("SELECT * FROM `permissions` ORDER BY description ASC");
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    
    public function getAllRoles() {
        $stmt = $this->conn->prepare("SELECT * FROM `roles` ORDER BY role_name ASC");
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function getAllRolesWithPermissions() {
        $roles = $this->getAllRoles();
        if (empty($roles)) {
            return [];
        }
        
        $roleIds = array_column($roles, 'id');
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $types = str_repeat('i', count($roleIds));
        
        $stmt = $this->conn->prepare("SELECT * FROM `role_permissions` WHERE role_id IN ($placeholders)");
        $stmt->bind_param($types, ...$roleIds);
        $stmt->execute();
        $permissions_map = db_stmt_to_assoc_array($stmt);

        foreach ($roles as &$role) {
            $role['permissions'] = [];
            foreach ($permissions_map as $map) {
                if ($map['role_id'] == $role['id']) {
                    $role['permissions'][] = $map['permission_id'];
                }
            }
        }
        return $roles;
    }

    public function saveRole($data) {
        if (empty($data['role_name'])) {
            return ['error' => 'نام نقش الزامی است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        try {
            $id = $data['id'] ?? null;
            $name = $data['role_name'];
            $description = $data['description'] ?? '';
            $permissions = $data['permissions'] ?? [];

            if ($id) {
                $stmt = $this->conn->prepare("UPDATE `roles` SET role_name = ?, description = ? WHERE id = ?");
                $stmt->bind_param("ssi", $name, $description, $id);
                $stmt->execute();
                $stmt->close();
                $roleId = $id;
            } else {
                $stmt = $this->conn->prepare("INSERT INTO `roles` (role_name, description) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $description);
                $stmt->execute();
                $roleId = $this->conn->insert_id;
                $stmt->close();
            }

            // A system role (مدیر کل) always keeps every permission, so nobody can
            // lock the administrators out by unticking a box.
            if ($this->hasIsSystemColumn()) {
                $sys = $this->conn->prepare("SELECT is_system FROM `roles` WHERE id = ?");
                $rid = (int)$roleId;
                $sys->bind_param('i', $rid);
                $sys->execute();
                if ((int)(db_stmt_to_assoc_array($sys)[0]['is_system'] ?? 0) === 1) {
                    $permissions = array_column($this->conn->query("SELECT id FROM `permissions`")->fetch_all(MYSQLI_ASSOC), 'id');
                }
            }

            // Sync permissions
            $stmt_delete = $this->conn->prepare("DELETE FROM `role_permissions` WHERE role_id = ?");
            $stmt_delete->bind_param("i", $roleId);
            $stmt_delete->execute();
            $stmt_delete->close();
            
            if (!empty($permissions)) {
                $stmt_insert = $this->conn->prepare("INSERT INTO `role_permissions` (role_id, permission_id) VALUES (?, ?)");
                foreach ($permissions as $permId) {
                    $stmt_insert->bind_param("ii", $roleId, $permId);
                    $stmt_insert->execute();
                }
                $stmt_insert->close();
            }

            $this->conn->commit();
            return ['success' => true, 'id' => $roleId];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => 'خطا در ذخیره نقش: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }
    
    /** True when the roles table already has the is_system column. */
    private function hasIsSystemColumn() {
        static $has = null;
        if ($has === null) {
            $res = $this->conn->query("SHOW COLUMNS FROM `roles` LIKE 'is_system'");
            $has = $res && $res->num_rows > 0;
        }
        return $has;
    }

    public function deleteRole($id) {
        $id = intval($id);
        if ($id <= 0) {
            return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }
        // System roles (e.g. مدیر کل) must not be deleted. The is_system flag
        // is added by the schema upgrader; until then, fall back to protecting
        // the first role (historical behavior).
        if ($this->hasIsSystemColumn()) {
            $stmt = $this->conn->prepare("SELECT is_system FROM `roles` WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->bind_result($isSystem);
            $found = $stmt->fetch();
            $stmt->close();
            if ($found && intval($isSystem) === 1) {
                return ['error' => 'نقش سیستمی قابل حذف نیست.', 'statusCode' => 403];
            }
        } elseif ($id === 1) {
            return ['error' => 'نقش مدیر کل قابل حذف نیست.', 'statusCode' => 403];
        }
        return parent::delete($id);
    }
}