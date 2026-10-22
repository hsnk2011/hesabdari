<?php
// /sso.php — one-time deep-link login from Zeema Admin
require_once __DIR__ . '/api/core/session.php';
app_session_start();
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/core/helpers.php';
require_once __DIR__ . '/api/models/User.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo 'توکن نامعتبر';
    exit;
}

$db = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$db->set_charset('utf8mb4');
$hash = hash('sha256', $token);

$stmt = $db->prepare(
    "SELECT id, user_id, expires_at, used_at FROM integration_sso_tokens WHERE token_hash = ? LIMIT 1"
);
$stmt->bind_param('s', $hash);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || $row['used_at'] || strtotime($row['expires_at']) < time()) {
    http_response_code(403);
    echo 'توکن منقضی یا استفاده شده است.';
    $db->close();
    exit;
}

$userId = intval($row['user_id']);
$userModel = new User($db);
$uStmt = $db->prepare("SELECT id, username FROM users WHERE id = ? LIMIT 1");
$uStmt->bind_param('i', $userId);
$uStmt->execute();
$user = $uStmt->get_result()->fetch_assoc();
$uStmt->close();

if (!$user) {
    http_response_code(404);
    echo 'کاربر یافت نشد.';
    $db->close();
    exit;
}

$now = date('Y-m-d H:i:s');
// Conditional update makes the one-time claim atomic; a concurrent request
// racing past the used_at check above will match 0 rows here.
$upd = $db->prepare("UPDATE integration_sso_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL");
$upd->bind_param('si', $now, $row['id']);
$upd->execute();
$claimed = $upd->affected_rows > 0;
$upd->close();
if (!$claimed) {
    http_response_code(403);
    echo 'توکن منقضی یا استفاده شده است.';
    $db->close();
    exit;
}

$access = $userModel->getUserAccessData($userId);

session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
$_SESSION['username'] = $user['username'];
$_SESSION['permissions'] = $access['permissions'] ?? [];
$_SESSION['allowed_entities'] = $access['entities'] ?? [];
$_SESSION['current_entity_id'] = !empty($access['entities'])
    ? $access['entities'][0]
    : (defined('DEFAULT_ENTITY_ID') ? DEFAULT_ENTITY_ID : 1);
$_SESSION['last_activity'] = time();
generate_csrf_token();

$db->close();
// Relative redirect — works regardless of the mount path (/hesabdari/, root, ...).
header('Location: index.php');
exit;
