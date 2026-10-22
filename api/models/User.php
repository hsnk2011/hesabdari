<?php
// /api/models/User.php
require_once __DIR__ . '/BaseModel.php';

/**
 * The User model handles all database operations related to users.
 */
class User extends BaseModel {
    protected $tableName = 'users';
    protected $hasEntityId = false;

    /**
     * Registers a new user in the database.
     * @param array $data Contains 'username' and 'password'.
     * @return array Result of the operation.
     */
    public function register($data) {
        if (empty($data['username']) || empty($data['password'])) {
            return ['error' => 'نام کاربری و رمز عبور الزامی است.', 'statusCode' => 400];
        }
        if (strlen($data['password']) < MIN_PASSWORD_LENGTH) {
            return ['error' => 'رمز عبور باید حداقل ' . MIN_PASSWORD_LENGTH . ' کاراکتر باشد.', 'statusCode' => 400];
        }

        $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $username = $data['username'];

        $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (username, password_hash) VALUES (?, ?)");
        $stmt->bind_param("ss", $username, $password_hash);

        if ($stmt->execute()) {
            $stmt->close();
            return ['success' => true, 'message' => 'کاربر با موفقیت ثبت شد.'];
        } else {
            $error_no = $this->conn->errno;
            $error_msg = $stmt->error;
            $stmt->close();
            if ($error_no == 1062) { // Duplicate entry error code
                return ['error' => 'این نام کاربری قبلا استفاده شده است.', 'statusCode' => 409];
            }
            return ['error' => 'خطا در ثبت کاربر: ' . $error_msg, 'statusCode' => 500];
        }
    }

    /**
     * Attempts to log in a user with rate limiting.
     * @param array $data Contains 'username' and 'password'.
     * @return array User data on success, error on failure.
     */
    public function login($data) {
        if (empty($data['username']) || empty($data['password'])) {
            return ['error' => 'نام کاربری و رمز عبور الزامی است.', 'statusCode' => 400];
        }

        $max_attempts = MAX_LOGIN_ATTEMPTS;
        $lockout_time_minutes = LOCKOUT_TIME_MINUTES;

        $stmt = $this->conn->prepare("SELECT id, username, password_hash, failed_login_attempts, lockout_until FROM `{$this->tableName}` WHERE username = ?");
        $stmt->bind_param("s", $data['username']);
        $stmt->execute();
        $user_data = db_stmt_to_assoc_array($stmt);

        if (count($user_data) == 1) {
            $user = $user_data[0];
            
            if ($user['lockout_until'] !== null) {
                $now = new DateTime();
                $lockout_time = new DateTime($user['lockout_until']);
                if ($now < $lockout_time) {
                    $remaining_seconds = $lockout_time->getTimestamp() - $now->getTimestamp();
                    $remaining_minutes = ceil($remaining_seconds / 60);
                    return ['error' => "حساب شما به دلیل تلاش‌های ناموفق متعدد، برای {$remaining_minutes} دقیقه قفل شده است.", 'statusCode' => 429];
                }
            }

            if (password_verify($data['password'], $user['password_hash'])) {
                $update_stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET failed_login_attempts = 0, lockout_until = NULL WHERE id = ?");
                $update_stmt->bind_param("i", $user['id']);
                $update_stmt->execute();
                $update_stmt->close();
                
                return ['success' => true, 'user' => ['id' => $user['id'], 'username' => $user['username']]];
            } else {
                $failed_attempts = $user['failed_login_attempts'] + 1;
                
                if ($failed_attempts >= $max_attempts) {
                    $now = new DateTime();
                    $now->add(new DateInterval("PT{$lockout_time_minutes}M"));
                    $new_lockout_until = $now->format('Y-m-d H:i:s');
                    
                    $update_stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET failed_login_attempts = ?, lockout_until = ? WHERE id = ?");
                    $update_stmt->bind_param("isi", $failed_attempts, $new_lockout_until, $user['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    return ['error' => "شما {$max_attempts} بار رمز را اشتباه وارد کردید. حساب شما برای {$lockout_time_minutes} دقیقه قفل شد.", 'statusCode' => 429];
                } else {
                    $update_stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET failed_login_attempts = ? WHERE id = ?");
                    $update_stmt->bind_param("ii", $failed_attempts, $user['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
        }
        
        return ['error' => 'نام کاربری یا رمز عبور اشتباه است.', 'statusCode' => 401];
    }

    /**
     * Changes the password for a given user.
     * @param int $userId The ID of the user.
     * @param string $currentPassword The user's current password.
     * @param string $newPassword The new password.
     * @return array Result of the operation.
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        if (empty($currentPassword) || empty($newPassword)) {
            return ['error' => 'رمز عبور فعلی و جدید الزامی است.', 'statusCode' => 400];
        }
        if (strlen($newPassword) < MIN_PASSWORD_LENGTH) {
            return ['error' => 'رمز عبور جدید باید حداقل ' . MIN_PASSWORD_LENGTH . ' کاراکتر باشد.', 'statusCode' => 400];
        }

        $stmt = $this->conn->prepare("SELECT password_hash FROM `{$this->tableName}` WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        if (empty($result)) {
            return ['error' => 'کاربر یافت نشد.', 'statusCode' => 404];
        }

        $password_hash = $result[0]['password_hash'];

        if (!password_verify($currentPassword, $password_hash)) {
            return ['error' => 'رمز عبور فعلی شما اشتباه است.', 'statusCode' => 403];
        }
        
        $new_password_hash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $update_stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET password_hash = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_password_hash, $userId);
        
        if ($update_stmt->execute()) {
            $update_stmt->close();
            return ['success' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد.'];
        }
        
        $update_stmt->close();
        return ['error' => 'خطا در بروزرسانی رمز عبور.', 'statusCode' => 500];
    }

    /**
     * Resets a user's password (by an admin).
     * @param int $userId The ID of the user to update.
     * @param string $newPassword The new password.
     * @return array Result of the operation.
     */
    public function adminResetPassword($userId, $newPassword) {
        $minLen = defined('MIN_PASSWORD_LENGTH') ? MIN_PASSWORD_LENGTH : 8;
        if (empty($newPassword) || strlen($newPassword) < $minLen) {
            return ['error' => 'رمز عبور جدید باید حداقل ' . $minLen . ' کاراکتر باشد.', 'statusCode' => 400];
        }
        
        $new_password_hash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET password_hash = ? WHERE id = ?");
        $stmt->bind_param("si", $new_password_hash, $userId);
        
        if ($stmt->execute()) {
            $stmt->close();
            return ['success' => true, 'message' => 'رمز عبور کاربر با موفقیت بازنشانی شد.'];
        }

        $stmt->close();
        return ['error' => 'خطا در بازنشانی رمز عبور.', 'statusCode' => 500];
    }

    /**
     * Deletes a user. Overrides BaseModel's delete to prevent self-deletion.
     * @param int $userIdToDelete The ID of the user to delete.
     * @param int $currentUserId The ID of the user performing the action.
     * @return array Result of the operation.
     */
    public function deleteUser($userIdToDelete, $currentUserId) {
        if ($userIdToDelete == $currentUserId) {
            return ['error' => 'شما نمی‌توانید حساب کاربری خود را حذف کنید.', 'statusCode' => 403];
        }
        return parent::delete($userIdToDelete);
    }
    
    public function getUserAccessData($userId) {
        $permissions = [];
        $stmt_perms = $this->conn->prepare("
            SELECT DISTINCT p.permission_key
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            JOIN user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $stmt_perms->bind_param("i", $userId);
        $stmt_perms->execute();
        $result_perms = db_stmt_to_assoc_array($stmt_perms);
        if ($result_perms) {
            $permissions = array_column($result_perms, 'permission_key');
        }
        if (!workspace_enabled($this->conn)) {
            $permissions = array_values(array_diff($permissions, WORKSPACE_PERMISSIONS));
        }

        $entities = [];
        $stmt_entities = $this->conn->prepare("SELECT entity_id FROM user_entity_access WHERE user_id = ?");
        $stmt_entities->bind_param("i", $userId);
        $stmt_entities->execute();
        $result_entities = db_stmt_to_assoc_array($stmt_entities);
        if ($result_entities) {
             $entities = array_map('intval', array_column($result_entities, 'entity_id'));
        }

        return ['permissions' => $permissions, 'entities' => $entities];
    }
    
    public function getAllUsersWithAccess() {
        $users_res = $this->conn->query("SELECT id, username FROM users ORDER BY username ASC");
        
        $users = [];
        if ($users_res) {
            while ($row = $users_res->fetch_assoc()) {
                $users[] = $row;
            }
        }
        
        if(empty($users)) return [];

        $userIds = array_column($users, 'id');
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        
        $roles_stmt = $this->conn->prepare("SELECT * FROM user_roles WHERE user_id IN ($placeholders)");
        $roles_stmt->bind_param($types, ...$userIds);
        $roles_stmt->execute();
        $roles_map = db_stmt_to_assoc_array($roles_stmt);

        $entities_stmt = $this->conn->prepare("SELECT * FROM user_entity_access WHERE user_id IN ($placeholders)");
        $entities_stmt->bind_param($types, ...$userIds);
        $entities_stmt->execute();
        $entities_map = db_stmt_to_assoc_array($entities_stmt);

        foreach ($users as &$user) {
            $user['roles'] = [];
            foreach ($roles_map as $map) {
                if ($map['user_id'] == $user['id']) {
                    $user['roles'][] = $map['role_id'];
                }
            }
            $user['entities'] = [];
            foreach ($entities_map as $map) {
                if ($map['user_id'] == $user['id']) {
                    $user['entities'][] = $map['entity_id'];
                }
            }
        }
        
        return $users;
    }

    public function updateUserAccess($data) {
        $userId = intval($data['userId'] ?? 0);
        if ($userId <= 0) {
            return ['error' => 'شناسه کاربر نامعتبر است.', 'statusCode' => 400];
        }

        $roles = $data['roles'] ?? [];
        $entities = $data['entities'] ?? [];

        $this->conn->begin_transaction();
        try {
            // Sync roles
            $stmt_del_roles = $this->conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
            $stmt_del_roles->bind_param("i", $userId);
            $stmt_del_roles->execute();
            $stmt_del_roles->close();

            if (!empty($roles)) {
                $stmt_add_roles = $this->conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($roles as $roleId) {
                    $stmt_add_roles->bind_param("ii", $userId, $roleId);
                    $stmt_add_roles->execute();
                }
                $stmt_add_roles->close();
            }

            // Sync entities
            $stmt_del_entities = $this->conn->prepare("DELETE FROM user_entity_access WHERE user_id = ?");
            $stmt_del_entities->bind_param("i", $userId);
            $stmt_del_entities->execute();
            $stmt_del_entities->close();

            if (!empty($entities)) {
                $stmt_add_entities = $this->conn->prepare("INSERT INTO user_entity_access (user_id, entity_id) VALUES (?, ?)");
                foreach ($entities as $entityId) {
                    $stmt_add_entities->bind_param("ii", $userId, $entityId);
                    $stmt_add_entities->execute();
                }
                $stmt_add_entities->close();
            }

            $this->conn->commit();
            return ['success' => true];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => 'خطا در بروزرسانی دسترسی‌ها: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }
}