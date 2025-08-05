<?php
// /api/core/helpers.php

/**
 * Sends a JSON response to the client and terminates the script.
 *
 * @param mixed $data The data to be encoded in JSON format.
 * @param int $statusCode The HTTP status code to send.
 */
function send_json($data, $statusCode = 200) {
    // Ensure no previous output interferes with the JSON response.
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    
    // Handle potential errors in the data structure before sending
    if (isset($data['error'])) {
        $errorResponse = ['error' => $data['error']];
        if (isset($data['message'])) {
             $errorResponse['message'] = $data['message'];
        }
        echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    } else {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_PRESERVE_ZERO_FRACTION);
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
    // Only log if a user is logged in.
    if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
        $user_id = $_SESSION['user_id'];
        $username = $_SESSION['username'];

        // Use a prepared statement to prevent SQL injection.
        $stmt = $conn->prepare("INSERT INTO activity_log (user_id, username, action_type, description) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isss", $user_id, $username, $action_type, $description);
            $stmt->execute();
            $stmt->close();
        }

        // Keep the log table trimmed to the last 500 entries for performance.
        $conn->query("DELETE FROM activity_log WHERE id NOT IN (SELECT id FROM (SELECT id FROM activity_log ORDER BY id DESC LIMIT 500) as temp_table)");
    }
}