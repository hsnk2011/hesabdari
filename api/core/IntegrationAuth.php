<?php
// /api/core/IntegrationAuth.php

/**
 * Service-to-service API token authentication for Medusa ↔ Hesabdari integration.
 */
class IntegrationAuth {
    /** @var mysqli */
    private $conn;
    /** @var array|null */
    private $tokenRow = null;

    public function __construct($db) {
        $this->conn = $db;
    }

    public static function extractBearerOrHeader() {
        $header = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
        if ($header !== '') {
            return trim($header);
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    public function authenticate($rawToken) {
        if ($rawToken === '') {
            return false;
        }
        $hash = hash('sha256', $rawToken);
        $stmt = $this->conn->prepare(
            "SELECT id, name, entity_id, scopes, is_active FROM api_tokens WHERE token_hash = ? LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = null;
        if (function_exists('db_stmt_to_assoc_array')) {
            $rows = db_stmt_to_assoc_array($stmt, false);
            $row = $rows[0] ?? null;
            $stmt->close();
        } else {
            $result = method_exists($stmt, 'get_result') ? $stmt->get_result() : false;
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
        }

        if (!$row || intval($row['is_active']) !== 1) {
            return false;
        }

        $this->tokenRow = $row;
        $now = date('Y-m-d H:i:s');
        $upd = $this->conn->prepare("UPDATE api_tokens SET last_used_at = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param('si', $now, $row['id']);
            $upd->execute();
            $upd->close();
        }

        // Token requests are stateless: never create or reuse a browser session
        // (a shared cookie would otherwise carry token privileges).
        if (session_status() == PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        $_SESSION['integration_auth'] = true;
        $_SESSION['integration_token_id'] = intval($row['id']);
        $_SESSION['current_entity_id'] = intval($row['entity_id']);
        $_SESSION['allowed_entities'] = [intval($row['entity_id'])];
        // Do not set a fake users.id — activity_log FK would fail
        unset($_SESSION['user_id']);
        $_SESSION['username'] = 'integration:' . $row['name'];
        $_SESSION['permissions'] = $this->parseScopes($row['scopes']);

        return true;
    }

    public function getTokenRow() {
        return $this->tokenRow;
    }

    public function hasScope($scope) {
        $perms = $_SESSION['permissions'] ?? [];
        return in_array('*', $perms, true) || in_array($scope, $perms, true) || in_array('integration', $perms, true);
    }

    private function parseScopes($scopesRaw) {
        $scopes = json_decode($scopesRaw, true);
        if (!is_array($scopes) || empty($scopes)) {
            return ['integration', '*'];
        }
        return $scopes;
    }

    /**
     * Create a new API token. Returns plaintext token once.
     */
    public function createToken($name, $entityId, $scopes = ['*']) {
        $plain = 'hz_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $plain);
        $prefix = substr($plain, 0, 10);
        $scopesJson = json_encode(array_values($scopes));
        $stmt = $this->conn->prepare(
            "INSERT INTO api_tokens (name, token_hash, token_prefix, entity_id, scopes, is_active) VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->bind_param('sssis', $name, $hash, $prefix, $entityId, $scopesJson);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['error' => $err, 'statusCode' => 500];
        }
        $id = $this->conn->insert_id;
        $stmt->close();
        return [
            'success' => true,
            'id' => $id,
            'token' => $plain,
            'token_prefix' => $prefix,
            'name' => $name,
            'entity_id' => $entityId,
        ];
    }

    public function listTokens() {
        $rows = [];
        $res = $this->conn->query(
            "SELECT id, name, token_prefix, entity_id, scopes, is_active, last_used_at, created_at FROM api_tokens ORDER BY id DESC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['scopes'] = json_decode($row['scopes'], true) ?: [];
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function revokeToken($id) {
        $stmt = $this->conn->prepare("UPDATE api_tokens SET is_active = 0 WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

function is_integration_auth() {
    return !empty($_SESSION['integration_auth']);
}
