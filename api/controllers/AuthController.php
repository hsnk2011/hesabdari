<?php
// /api/controllers/AuthController.php

require_once __DIR__ . '/../models/User.php';

/**
 * The AuthController handles all requests related to user authentication and management.
 */
class AuthController {
    private $conn;
    private $userModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->userModel = new User($db);
    }

    /**
     * Handles user login requests.
     * @param array $data Contains 'username' and 'password'.
     */
    public function login($data) {
        $result = $this->userModel->login($data);

        if (isset($result['success']) && $result['success']) {
            // Set session variables upon successful login.
            $_SESSION['user_id'] = $result['user']['id'];
            $_SESSION['username'] = $result['user']['username'];
            log_activity($this->conn, 'LOGIN', "کاربر «{$result['user']['username']}» وارد سیستم شد.");
            send_json(['success' => true, 'username' => $result['user']['username']]);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 401);
        }
    }
    
    /**
     * Handles user logout requests by destroying the session.
     */
    public function logout() {
        if (isset($_SESSION['username'])) {
            log_activity($this->conn, 'LOGOUT', "کاربر «{$_SESSION['username']}» از سیستم خارج شد.");
        }
        session_unset();
        session_destroy();
        send_json(['success' => true]);
    }

    /**
     * Handles new user registration requests.
     * @param array $data Contains 'username' and 'password'.
     */
    public function register($data) {
        $result = $this->userModel->register($data);
        if (isset($result['success']) && $result['success']) {
            log_activity($this->conn, 'REGISTER_USER', "کاربر جدید «{$data['username']}» ثبت شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Checks if a user session is currently active.
     */
    public function checkSession() {
        if (isset($_SESSION['user_id'])) {
            send_json(['loggedIn' => true, 'username' => $_SESSION['username']]);
        } else {
            send_json(['loggedIn' => false]);
        }
    }

    /**
     * Handles requests for a user to change their own password.
     * @param array $data Contains 'current_password' and 'new_password'.
     */
    public function changePassword($data) {
        $userId = $_SESSION['user_id'];
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        $result = $this->userModel->changePassword($userId, $currentPassword, $newPassword);

        if (isset($result['success']) && $result['success']) {
             log_activity($this->conn, 'CHANGE_PASSWORD', "کاربر «{$_SESSION['username']}» رمز عبور خود را تغییر داد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles requests for an admin to reset a user's password.
     * @param array $data Contains 'userId' and 'newPassword'.
     */
    public function adminResetPassword($data) {
        $userId = $data['userId'] ?? null;
        $newPassword = $data['newPassword'] ?? '';
        
        $result = $this->userModel->adminResetPassword($userId, $newPassword);
        
        if (isset($result['success']) && $result['success']) {
            log_activity($this->conn, 'ADMIN_PASS_RESET', "رمز عبور کاربر با شناسه {$userId} توسط ادمین تغییر داده شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles requests to delete a user.
     * @param array $data Contains 'userId'.
     */
    public function deleteUser($data) {
        $userIdToDelete = $data['userId'] ?? null;
        $currentUserId = $_SESSION['user_id'];

        $result = $this->userModel->deleteUser($userIdToDelete, $currentUserId);
        
        if (isset($result['success']) && $result['success']) {
            log_activity($this->conn, 'DELETE_USER', "کاربر با شناسه {$userIdToDelete} توسط ادمین حذف شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
}