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

    private function _getBusinessEntities() {
        $entities_res = $this->conn->query("SELECT id, name FROM business_entities ORDER BY id ASC");
        $entities = [];
        if ($entities_res) {
            while ($row = $entities_res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $entities[] = $row;
            }
        }
        return $entities;
    }

    /**
     * Handles user login requests.
     * @param array $data Contains 'username' and 'password'.
     */
    public function login($data) {
        try {
            $result = $this->userModel->login($data);

            if (isset($result['success']) && $result['success']) {
                session_regenerate_id(true);
                generate_csrf_token();

                $_SESSION['user_id'] = $result['user']['id'];
                $_SESSION['username'] = $result['user']['username'];
                
                $access_data = $this->userModel->getUserAccessData($result['user']['id']);
                $_SESSION['permissions'] = $access_data['permissions'];
                $_SESSION['allowed_entities'] = $access_data['entities'];

                if (!empty($access_data['entities'])) {
                     $_SESSION['current_entity_id'] = $access_data['entities'][0];
                } else {
                     $_SESSION['current_entity_id'] = 0;
                }

                log_activity($this->conn, 'LOGIN', "کاربر «{$result['user']['username']}» وارد سیستم شد.");
                touch_session_activity();
                
                $entities = $this->_getBusinessEntities();

                send_json([
                    'success' => true,
                    'loggedIn' => true, 
                    'username' => $result['user']['username'],
                    'csrf_token' => $_SESSION['csrf_token'],
                    'current_entity_id' => $_SESSION['current_entity_id'],
                    'business_entities' => $entities,
                    'permissions' => $_SESSION['permissions'],
                    'allowed_entities' => $_SESSION['allowed_entities'],
                    'session_expires_at' => get_session_expires_at(),
                ]);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 401);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای داخلی سرور هنگام ورود: ' . $e->getMessage()], 500);
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
        // Permission check is handled by the router
        try {
            $result = $this->userModel->register($data);
            if (isset($result['success']) && $result['success']) {
                log_activity($this->conn, 'REGISTER_USER', "کاربر جدید «{$data['username']}» ثبت شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام ثبت کاربر: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Checks if a user session is currently active.
     */
    public function checkSession() {
        try {
            if (isset($_SESSION['user_id']) && enforce_session_timeout()) {
                touch_session_activity();
                $entities = $this->_getBusinessEntities();

                // Always echo the session's CSRF token: api.js refreshes the meta
                // tag from it, which self-heals pages whose cached HTML carried a
                // stale token (e.g. service-worker fallback pages).
                if (empty($_SESSION['csrf_token'])) {
                    generate_csrf_token();
                }

                send_json([
                    'loggedIn' => true,
                    'username' => $_SESSION['username'],
                    'current_entity_id' => $_SESSION['current_entity_id'] ?? (defined('DEFAULT_ENTITY_ID') ? DEFAULT_ENTITY_ID : 1),
                    'business_entities' => $entities,
                    'permissions' => $_SESSION['permissions'] ?? [],
                    'allowed_entities' => $_SESSION['allowed_entities'] ?? [],
                    'session_expires_at' => get_session_expires_at(),
                    'csrf_token' => $_SESSION['csrf_token'],
                ]);
            } else {
                send_json(['loggedIn' => false]);
            }
        } catch (Exception $e) {
            send_json(['loggedIn' => false, 'error' => 'خطای سرور هنگام بررسی جلسه.'], 500);
        }
    }

    /**
     * Handles requests for a user to change their own password.
     * @param array $data Contains 'current_password' and 'new_password'.
     */
    public function changePassword($data) {
        try {
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
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام تغییر رمز عبور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles requests for an admin to reset a user's password.
     * @param array $data Contains 'userId' and 'newPassword'.
     */
    public function adminResetPassword($data) {
        // Permission check is handled by the router
        try {
            $userId = $data['userId'] ?? null;
            $newPassword = $data['newPassword'] ?? '';
            
            $result = $this->userModel->adminResetPassword($userId, $newPassword);
            
            if (isset($result['success']) && $result['success']) {
                log_activity($this->conn, 'ADMIN_PASS_RESET', "رمز عبور کاربر با شناسه {$userId} توسط ادمین تغییر داده شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام بازنشانی رمز عبور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles requests to delete a user.
     * @param array $data Contains 'userId'.
     */
    public function deleteUser($data) {
        // Permission check is handled by the router
        try {
            $userIdToDelete = $data['userId'] ?? null;
            $currentUserId = $_SESSION['user_id'];

            $result = $this->userModel->deleteUser($userIdToDelete, $currentUserId);
            
            if (isset($result['success']) && $result['success']) {
                log_activity($this->conn, 'DELETE_USER', "کاربر با شناسه {$userIdToDelete} توسط ادمین حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف کاربر: ' . $e->getMessage()], 500);
        }
    }
}