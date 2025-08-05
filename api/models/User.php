<?php
// /api/models/User.php
require_once __DIR__ . '/BaseModel.php';

/**
 * The User model handles all database operations related to users.
 */
class User extends BaseModel {
    protected $tableName = 'users';

    /**
     * Registers a new user in the database.
     * @param array $data Contains 'username' and 'password'.
     * @return array Result of the operation.
     */
    public function register($data) {
        if (empty($data['username']) || empty($data['password'])) {
            return ['error' => 'نام کاربری و رمز عبور الزامی است.', 'statusCode' => 400];
        }
        if (strlen($data['password']) < 6) {
            return ['error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.', 'statusCode' => 400];
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
     * Attempts to log in a user. (Compatible version without get_result)
     * @param array $data Contains 'username' and 'password'.
     * @return array User data on success, error on failure.
     */
    public function login($data) {
        if (empty($data['username']) || empty($data['password'])) {
            return ['error' => 'نام کاربری و رمز عبور الزامی است.', 'statusCode' => 400];
        }

        $stmt = $this->conn->prepare("SELECT id, username, password_hash FROM `{$this->tableName}` WHERE username = ?");
        $stmt->bind_param("s", $data['username']);
        $stmt->execute();
        $stmt->store_result(); // Important for num_rows and bind_result

        if ($stmt->num_rows == 1) {
            $id = null;
            $username = null;
            $password_hash = null;
            $stmt->bind_result($id, $username, $password_hash);
            $stmt->fetch();

            if (password_verify($data['password'], $password_hash)) {
                $stmt->close();
                return ['success' => true, 'user' => ['id' => $id, 'username' => $username]];
            }
        }
        
        $stmt->close();
        return ['error' => 'نام کاربری یا رمز عبور اشتباه است.', 'statusCode' => 401];
    }

    /**
     * Changes the password for a given user. (Compatible version without get_result)
     * @param int $userId The ID of the user.
     * @param string $currentPassword The user's current password.
     * @param string $newPassword The new password.
     * @return array Result of the operation.
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        if (empty($currentPassword) || empty($newPassword)) {
            return ['error' => 'رمز عبور فعلی و جدید الزامی است.', 'statusCode' => 400];
        }
        if (strlen($newPassword) < 6) {
            return ['error' => 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.', 'statusCode' => 400];
        }

        $stmt = $this->conn->prepare("SELECT password_hash FROM `{$this->tableName}` WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows == 0) {
            $stmt->close();
            return ['error' => 'کاربر یافت نشد.', 'statusCode' => 404];
        }

        $password_hash = null;
        $stmt->bind_result($password_hash);
        $stmt->fetch();
        $stmt->close();

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
        if (empty($newPassword) || strlen($newPassword) < 6) {
            return ['error' => 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.', 'statusCode' => 400];
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
}