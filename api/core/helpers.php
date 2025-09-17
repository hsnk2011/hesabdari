<?php
// /api/core/helpers.php

/**
 * Generates and stores a new CSRF token in the session.
 */
function generate_csrf_token() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Verifies the CSRF token from the request against the one in the session.
 * @return bool True if valid, false otherwise.
 */
function verify_csrf_token() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    $header_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!$header_token || !isset($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $header_token);
}


/**
 * Recursively sanitizes an array or object for safe HTML output.
 *
 * @param mixed $data The data to sanitize.
 * @return mixed The sanitized data.
 */
function sanitize_output($data) {
    if (is_string($data)) {
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    if (is_array($data)) {
        $sanitized_array = [];
        foreach ($data as $key => $value) {
            $sanitized_array[$key] = sanitize_output($value);
        }
        return $sanitized_array;
    }
    if (is_object($data)) {
        $sanitized_object = new stdClass();
        foreach ($data as $key => $value) {
            $sanitized_object->{$key} = sanitize_output($value);
        }
        return $sanitized_object;
    }
    return $data;
}


/**
 * Sends a JSON response to the client and terminates the script.
 *
 * @param mixed $data The data to be encoded in JSON format.
 * @param int $statusCode The HTTP status code to send.
 */
function send_json($data, $statusCode = 200) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    
    $sanitized_data = sanitize_output($data);
    
    if (isset($sanitized_data['error'])) {
        $errorResponse = ['error' => $sanitized_data['error']];
        if (isset($sanitized_data['message'])) {
             $errorResponse['message'] = $sanitized_data['message'];
        }
        echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    } else {
        echo json_encode($sanitized_data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_PRESERVE_ZERO_FRACTION);
    }
    
    exit();
}

/**
 * Logs a user's activity in the database.
 * Also cleans up old log entries to keep the log table from growing indefinitely.
 *
 * @param mysqli $conn The database connection object.
 * @param string $action_type A category for the action (e.g., 'LOGIN', 'SAVE_CUSTOMER').
 * @param string $description A detailed description of the action performed.
 */
function log_activity($conn, $action_type, $description) {
    if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
        $user_id = $_SESSION['user_id'];
        $username = $_SESSION['username'];

        $stmt = $conn->prepare("INSERT INTO activity_log (user_id, username, action_type, description) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isss", $user_id, $username, $action_type, $description);
            $stmt->execute();
            $stmt->close();
        }

        $conn->query("DELETE FROM activity_log WHERE id NOT IN (SELECT id FROM (SELECT id FROM activity_log ORDER BY id DESC LIMIT 500) as temp_table)");
    }
}

/**
 * A compatible replacement for get_result() to fetch an associative array from a prepared statement.
 * This is crucial for environments without the mysqlnd driver.
 *
 * @param mysqli_stmt $stmt The executed statement.
 * @return array The result set as an array of associative arrays.
 */
function db_stmt_to_assoc_array($stmt) {
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        if (is_object($stmt)) $stmt->close();
        return [];
    }
    $meta = $stmt->result_metadata();
    $fields = [];
    $row = [];
    while ($field = $meta->fetch_field()) {
        $fields[] = &$row[$field->name];
    }
    call_user_func_array([$stmt, 'bind_result'], $fields);
    $result = [];
    while ($stmt->fetch()) {
        $c = [];
        foreach ($row as $key => $val) {
            $c[$key] = $val;
        }
        $result[] = $c;
    }
    if (is_object($stmt)) $stmt->close();
    return $result;
}