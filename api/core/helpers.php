<?php
// /api/core/helpers.php

// Installs whose config.php predates the timezone line would otherwise run on
// the server default (usually UTC) and date every document 3.5h off.
if (date_default_timezone_get() === 'UTC' && !ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Tehran');
}

/**
 * Align the MySQL session clock (NOW(), CURDATE(), TIMESTAMP columns) with PHP's
 * timezone so SQL-side dates match date() used everywhere else.
 */
function db_sync_timezone($conn) {
    if (!($conn instanceof mysqli)) {
        return;
    }
    $offset = date('P'); // e.g. +03:30
    try {
        $conn->query("SET time_zone = '" . $conn->real_escape_string($offset) . "'");
    } catch (Throwable $e) {
        // Non-fatal: keep server default.
    }
}

/**
 * Entity for report queries. A client-supplied entityId is honoured only when
 * it is one of the user's entities (CLI/tests may pass any); otherwise the
 * current entity is used.
 */
function report_entity_id($data) {
    $current = (int)($_SESSION['current_entity_id'] ?? 0);
    $requested = isset($data['entityId']) ? (int)$data['entityId'] : 0;
    if ($requested <= 0 || $requested === $current) {
        return $current;
    }
    if (PHP_SAPI === 'cli') {
        return $requested;
    }
    $allowed = array_map('intval', $_SESSION['allowed_entities'] ?? []);
    return in_array($requested, $allowed, true) ? $requested : $current;
}

/**
 * Replaces the HTML tag characters < and > with the look-alike angle quotes
 * ‹ › so stored names/descriptions can never become markup in any page
 * (many screens build HTML from these values). Applied recursively.
 */
function neutralize_markup($value, $skipKeyPattern = '/pass|secret|token|key|hash|signature/i') {
    if (is_string($value)) {
        return strtr($value, ['<' => '‹', '>' => '›']);
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if (is_string($k) && $skipKeyPattern && preg_match($skipKeyPattern, $k)) {
                continue;
            }
            $value[$k] = neutralize_markup($v, $skipKeyPattern);
        }
    }
    return $value;
}

/**
 * True when $allowedEntities covers every entity in the database.
 */
function user_has_all_entities($conn, $allowedEntities) {
    $allowed = array_map('intval', (array)$allowedEntities);
    $res = $conn->query("SELECT id FROM business_entities");
    if (!$res) {
        return false;
    }
    while ($row = $res->fetch_assoc()) {
        if (!in_array((int)$row['id'], $allowed, true)) {
            return false;
        }
    }
    return true;
}

/**
 * True when row $id of $table belongs to the current entity.
 */
function entity_owns($conn, $table, $id) {
    $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
    $id = (int)$id;
    if ($id <= 0 || $entityId <= 0) {
        return false;
    }
    $stmt = $conn->prepare("SELECT 1 FROM `{$table}` WHERE id = ? AND entity_id = ? LIMIT 1");
    $stmt->bind_param('ii', $id, $entityId);
    $stmt->execute();
    return !empty(db_stmt_to_assoc_array($stmt));
}

/**
 * Grand total of an invoice row (totalAmount − discount + tax).
 */
function invoice_grand_total(array $invoice) {
    return round(
        floatval($invoice['totalAmount'] ?? 0)
        - floatval($invoice['discount'] ?? 0)
        + floatval($invoice['tax_amount'] ?? 0),
        2
    );
}

/**
 * Normalize a money input that may arrive locale-formatted ("1,250,000" or Persian digits).
 */
function parse_money($value) {
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    $s = (string)$value;
    $s = strtr($s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                    '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9', '٬' => ',', '،' => ',']);
    $s = str_replace([',', ' '], '', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

/**
 * Permissions that belong to «میز کار». When the workspace is switched off in
 * Settings they are dropped from every user's session (see User::getUserAccessData),
 * so the tab, its buttons and its API actions all go away together while the
 * role assignments stay in the database for when it is switched back on.
 */
const WORKSPACE_PERMISSIONS = [
    'use_crm', 'manage_crm_team', 'use_projects', 'manage_projects', 'use_messenger',
    'use_cartable', 'approve_payments', 'pay_payments', 'manage_hr', 'use_ideas',
];

/**
 * Whether «میز کار» is on (settings.workspace_enabled, default on). Not cached,
 * so a change in Settings is seen by the next permission refresh and scheduler run.
 */
function workspace_enabled($conn) {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'workspace_enabled' LIMIT 1");
    if (!$stmt) {
        return true;
    }
    $value = null;
    if ($stmt->execute()) {
        $stmt->bind_result($value);
        $stmt->fetch();
    }
    $stmt->close();
    return $value !== '0';
}

/**
 * Read a single row from the `settings` table with a per-request cache.
 * Falls back to $default when the key is missing or empty — callers must
 * choose defaults that preserve the behavior that existed before the
 * setting was introduced.
 *
 * @param mysqli $conn
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function get_app_setting($conn, $key, $default = null) {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $value = $default;
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $key);
        if ($stmt->execute()) {
            $stmt->bind_result($rowValue);
            if ($stmt->fetch() && $rowValue !== null && $rowValue !== '') {
                $value = $rowValue;
            }
        }
        $stmt->close();
    }
    $cache[$key] = $value;
    return $value;
}

/**
 * Area (m²) for a carpet dimension string.
 * Named dimensions use `dimension_area_map`; numeric WxH sides above
 * `dimension_cm_threshold` are treated as centimeters.
 */
/**
 * Parse WxH dimension into meter sides [w, h], or null if not a numeric pair.
 * Named sizes (e.g. پادری) return null.
 */
function dimension_sides_meters($conn, $dimension) {
    $dim = trim((string)$dimension);
    if ($dim === '') {
        return null;
    }
    $mapJson = get_app_setting($conn, 'dimension_area_map', '{"پادری":0.4}');
    $map = json_decode((string)$mapJson, true);
    if (!is_array($map)) {
        $map = ['پادری' => 0.4];
    }
    if (array_key_exists($dim, $map)) {
        return null;
    }
    $threshold = floatval(get_app_setting($conn, 'dimension_cm_threshold', 15));
    if ($threshold <= 0) {
        $threshold = 15;
    }
    $parts = explode('x', str_replace(['*', '×'], 'x', mb_strtolower($dim)));
    if (count($parts) !== 2) {
        return null;
    }
    $width = floatval($parts[0]);
    $height = floatval($parts[1]);
    if (!($width > 0) || !($height > 0)) {
        return null;
    }
    if ($width > $threshold) {
        $width /= 100;
    }
    if ($height > $threshold) {
        $height /= 100;
    }
    return [$width, $height];
}

function dimension_area($conn, $dimension) {
    $sides = dimension_sides_meters($conn, $dimension);
    if ($sides === null) {
        $dim = trim((string)$dimension);
        if ($dim === '') {
            return 0.0;
        }
        $mapJson = get_app_setting($conn, 'dimension_area_map', '{"پادری":0.4}');
        $map = json_decode((string)$mapJson, true);
        if (!is_array($map)) {
            $map = ['پادری' => 0.4];
        }
        if (array_key_exists($dim, $map)) {
            return floatval($map[$dim]);
        }
        return 0.0;
    }
    return $sides[0] * $sides[1];
}

/**
 * نسبت ضلع بلندتر به کوتاه‌تر (طول ÷ عرض). برای کناره معمولاً ≥ ۱.۷۵.
 */
function dimension_aspect_ratio($conn, $dimension) {
    $sides = dimension_sides_meters($conn, $dimension);
    if ($sides === null) {
        return 0.0;
    }
    $short = min($sides[0], $sides[1]);
    $long = max($sides[0], $sides[1]);
    if (!($short > 0)) {
        return 0.0;
    }
    return $long / $short;
}

/** حداقل نسبت طول به عرض برای فیلتر «کناره» */
function kenareh_min_aspect_ratio() {
    return 1.75;
}

/**
 * Rescale a line unit price when delivered dimensions change.
 * Keeps implied price-per-m²: new = old × (newArea / oldArea).
 */
function rescale_unit_price_by_area($conn, $oldDimensions, $newDimensions, $oldUnitPrice) {
    $oldPrice = floatval($oldUnitPrice);
    $oldDim = trim((string)$oldDimensions);
    $newDim = trim((string)$newDimensions);
    if ($oldDim === $newDim || $oldDim === '' || $newDim === '') {
        return round($oldPrice, 2);
    }
    $oldArea = dimension_area($conn, $oldDim);
    $newArea = dimension_area($conn, $newDim);
    if (!($oldArea > 0) || !($newArea > 0)) {
        throw new Exception('متراژ ابعاد قبلی یا جدید قابل محاسبه نیست.', 400);
    }
    return round($oldPrice * ($newArea / $oldArea), 2);
}

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
/**
 * Whether the app runs in debug mode (detailed errors to clients).
 */
function is_app_debug() {
    return defined('APP_DEBUG') && APP_DEBUG === true;
}

/**
 * Returns a safe user-facing error message; logs full details server-side.
 *
 * @param Throwable|Exception $e
 * @param string $fallback Generic message when not in debug mode
 */
function safe_exception_message($e, $fallback = 'خطای داخلی سرور. لطفاً دوباره تلاش کنید.') {
    if (class_exists('AppLogger')) {
        AppLogger::exception($e, null, ['source' => 'safe_exception_message']);
    } else {
        error_log('[' . get_class($e) . '] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    if (is_app_debug()) {
        return $e->getMessage();
    }
    // Integration API callers need actionable errors (not a generic 500 string)
    if (function_exists('is_integration_auth') && is_integration_auth()) {
        $msg = trim((string)$e->getMessage());
        if ($msg !== '') {
            return $msg;
        }
    }
    $rid = class_exists('AppLogger') ? AppLogger::getRequestId() : '';
    if ($rid !== '') {
        return $fallback . " (کد پیگیری: {$rid})";
    }
    return $fallback;
}

/**
 * Convenience wrappers around AppLogger (no-op if logger unavailable).
 */
function app_log($level, $message, array $context = []) {
    if (class_exists('AppLogger')) {
        AppLogger::log($level, $message, $context);
    }
}

function app_log_error($message, array $context = []) {
    app_log('ERROR', $message, $context);
}

function app_log_warning($message, array $context = []) {
    app_log('WARNING', $message, $context);
}

function app_log_info($message, array $context = []) {
    app_log('INFO', $message, $context);
}

function app_log_debug($message, array $context = []) {
    app_log('DEBUG', $message, $context);
}

/**
 * Unix timestamp when the current session expires (for client-side warnings).
 */
function get_session_expires_at() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    $timeout = (defined('SESSION_TIMEOUT_MINUTES') ? SESSION_TIMEOUT_MINUTES : 120) * 60;
    $last = $_SESSION['last_activity'] ?? time();
    return intval($last) + $timeout;
}

/**
 * Normalize dimension strings for matching (192x150, 192*150, 192 X 150 → 192x150).
 */
function normalize_dimensions($dimensions) {
    $d = trim((string)($dimensions ?? ''));
    $d = str_replace(['×', '✕', '*', 'X'], 'x', $d);
    $d = preg_replace('/\s+/', '', $d);
    return strtolower($d);
}

/**
 * SQL expression to normalize a dimension column for comparison.
 */
function sql_normalize_dimensions_expr($column) {
    return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '*', 'x'), '×', 'x'), 'X', 'x'), '✕', 'x'))";
}

function send_json($data, $statusCode = 200) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    
    // $sanitized_data = sanitize_output($data); // REMOVED: Double escaping fix
    $sanitized_data = $data;
    
    // JSON_NUMERIC_CHECK removed: it corrupts numeric strings (leading zeros on
    // phone/national-id, float overflow on card numbers) across all API responses.
    // Remember the final response for request-level hooks (idempotency replay).
    $GLOBALS['__api_last_response'] = ['status' => $statusCode, 'data' => $data];

    if (isset($sanitized_data['error'])) {
        $errorResponse = ['error' => $sanitized_data['error']];
        if (isset($sanitized_data['message'])) {
             $errorResponse['message'] = $sanitized_data['message'];
        }
        if (!empty($sanitized_data['workspace_disabled'])) {
            $errorResponse['workspace_disabled'] = true;
        }
        if (isset($sanitized_data['request_id'])) {
            $errorResponse['request_id'] = $sanitized_data['request_id'];
        } elseif (class_exists('AppLogger') && $statusCode >= 500) {
            $errorResponse['request_id'] = AppLogger::getRequestId();
        }
        echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode($sanitized_data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
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
    // START FIX: Check if a session exists before trying to access it.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return; // Do nothing if there's no active session.
    }
    // END FIX

    // Skip activity log for service-to-service integration (no real user / FK)
    if (!empty($_SESSION['integration_auth'])) {
        return;
    }

    if (isset($_SESSION['user_id']) && isset($_SESSION['username']) && intval($_SESSION['user_id']) > 0) {
        $user_id = $_SESSION['user_id'];
        $username = $_SESSION['username'];

        $stmt = $conn->prepare("INSERT INTO activity_log (user_id, username, action_type, description) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isss", $user_id, $username, $action_type, $description);
            $stmt->execute();
            $stmt->close();
        }

        // Keep a year of history (a count cap of 500 rows only held about a week with several users).
        $retention_days = defined('ACTIVITY_LOG_RETENTION_DAYS') ? (int)ACTIVITY_LOG_RETENTION_DAYS : 365;
        // Throttle pruning: running this DELETE on every log write is expensive
        if (!isset($_SESSION['_log_prune_checked_at']) || (time() - $_SESSION['_log_prune_checked_at']) > 300) {
            $_SESSION['_log_prune_checked_at'] = time();
            $conn->query("DELETE FROM activity_log WHERE `timestamp` < NOW() - INTERVAL {$retention_days} DAY");
        }
    }
}

/**
 * آخرین تاریخ بسته‌شده برای یک مجموعه (فقط بستن‌های فعال).
 */
function get_entity_last_closed_date($conn, $entityId) {
    $stmt = $conn->prepare(
        "SELECT MAX(period_end_date) as last_date FROM account_closings WHERE entity_id = ? AND status = 'completed'"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $entityId);
    $stmt->execute();
    $row = db_stmt_to_assoc_array($stmt);
    return !empty($row[0]['last_date']) ? $row[0]['last_date'] : null;
}

/**
 * بررسی قابل ویرایش بودن تاریخ تراکنش نسبت به دوره‌های بسته‌شده.
 *
 * @throws Exception
 */
function assert_transaction_date_editable($conn, $entityId, $date) {
    if (empty($date)) {
        return;
    }
    $lastClosed = get_entity_last_closed_date($conn, $entityId);
    if ($lastClosed && $date <= $lastClosed) {
        throw new Exception(
            "این تاریخ در دوره بسته‌شده تا {$lastClosed} قرار دارد و قابل ویرایش نیست. برای تغییر، ابتدا آخرین بستن حساب را بازگشایی کنید.",
            403
        );
    }
}

/**
 * A compatible replacement for get_result() to fetch an associative array from a prepared statement.
 * This is crucial for environments without the mysqlnd driver.
 *
 * @param mysqli_stmt $stmt The executed statement.
 * @param bool $close_stmt Whether to close the statement after fetching.
 * @return array The result set as an array of associative arrays.
 */
function db_stmt_to_assoc_array($stmt, $close_stmt = true) {
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        if ($close_stmt && is_object($stmt)) $stmt->close();
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
    // Free the result memory
    $stmt->free_result();
    
    // Close the statement ONLY if requested
    if ($close_stmt && is_object($stmt)) {
        $stmt->close();
    }
    return $result;
}

/**
 * Ensure service/non-product invoice rows have a displayable productName.
 */
function normalize_invoice_item_display(array $item): array {
    if (!empty($item['is_service']) && empty($item['productName'])) {
        $item['productName'] = !empty($item['item_description']) ? $item['item_description'] : 'خدمات';
    }
    return $item;
}

/**
 * Checks if the current user has a specific permission.
 * Terminates with a 403 error if permission is denied.
 *
 * @param string $permission_key The permission to check for (e.g., 'manage_sales_invoices').
 */
function check_permission($permission_key) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['user_id'])) {
        send_json(['error' => 'دسترسی غیرمجاز. لطفا ابتدا وارد شوید.'], 401);
        return;
    }
    
    $user_permissions = $_SESSION['permissions'] ?? [];
    if (!in_array($permission_key, $user_permissions)) {
        send_json(['error' => 'شما دسترسی لازم برای انجام این عملیات را ندارید.'], 403);
        return;
    }

    $entity_agnostic_permissions = ['manage_settings'];
    if (!in_array($permission_key, $entity_agnostic_permissions)) {
        $allowed_entities = $_SESSION['allowed_entities'] ?? [];
        $current_entity = $_SESSION['current_entity_id'] ?? null;
        if ($current_entity === null || !in_array($current_entity, $allowed_entities)) {
             send_json(['error' => 'شما به این مجموعه تجاری دسترسی ندارید.'], 403);
             return;
        }
    }
}

/**
 * Passes when the user holds at least one of $permission_keys (then applies the
 * same login/entity checks as check_permission for that key).
 */
function check_any_permission(array $permission_keys) {
    $user_permissions = $_SESSION['permissions'] ?? [];
    foreach ($permission_keys as $key) {
        if (in_array($key, $user_permissions, true)) {
            check_permission($key);
            return;
        }
    }
    check_permission((string)($permission_keys[0] ?? '__none__'));
}

function enforce_session_timeout() {
    if (session_status() == PHP_SESSION_NONE) { session_start(); }
    $timeout = (defined('SESSION_TIMEOUT_MINUTES') ? SESSION_TIMEOUT_MINUTES : 120) * 60;
    $last = $_SESSION['last_activity'] ?? null;
    if (!isset($_SESSION['user_id'])) { return false; }
    if (!$last) { $_SESSION['last_activity'] = time(); return true; }
    if ((time() - intval($last)) <= $timeout) { return true; }
    session_unset();
    session_destroy();
    return false;
}

function touch_session_activity() {
    if (session_status() == PHP_SESSION_NONE) { session_start(); }
    $_SESSION['last_activity'] = time();
}

/**
 * Validates a date string format (YYYY-MM-DD).
 * 
 * @param string $date The date string to validate.
 * @return bool True if valid, false otherwise.
 */
function validate_date_format($date) {
    if (empty($date)) {
        return false;
    }
    // Check format YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    // Verify it's a real date
    $parts = explode('-', $date);
    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

/**
 * Validates and sanitizes a description field.
 * 
 * @param string $description The description to validate.
 * @param int $maxLength Maximum allowed length (default: 500).
 * @return array ['valid' => bool, 'sanitized' => string, 'error' => string|null]
 */
function validate_description($description, $maxLength = 500) {
    if (empty($description)) {
        return ['valid' => true, 'sanitized' => '', 'error' => null];
    }
    
    $description = trim($description);
    
    if (mb_strlen($description) > $maxLength) {
        return [
            'valid' => false, 
            'sanitized' => mb_substr($description, 0, $maxLength),
            'error' => "توضیحات نمی‌تواند بیشتر از {$maxLength} کاراکتر باشد."
        ];
    }
    
    return ['valid' => true, 'sanitized' => $description, 'error' => null];
}

/**
 * Validates numeric input to ensure it's a valid positive number.
 * 
 * @param mixed $value The value to validate.
 * @param bool $allowZero Whether to allow zero as a valid value.
 * @param bool $allowNegative Whether to allow negative values.
 * @return array ['valid' => bool, 'value' => float|int, 'error' => string|null]
 */
function validate_numeric($value, $allowZero = true, $allowNegative = false) {
    if (!is_numeric($value)) {
        return ['valid' => false, 'value' => 0, 'error' => 'مقدار وارد شده عددی نیست.'];
    }
    
    $num = floatval($value);
    
    if (!$allowZero && $num == 0) {
        return ['valid' => false, 'value' => $num, 'error' => 'مقدار نمی‌تواند صفر باشد.'];
    }
    
    if (!$allowNegative && $num < 0) {
        return ['valid' => false, 'value' => $num, 'error' => 'مقدار نمی‌تواند منفی باشد.'];
    }
    
    return ['valid' => true, 'value' => $num, 'error' => null];
}

/**
 * Convert Gregorian date to Jalali (precise algorithm)
 * @param string $gregorianDate Format: YYYY-MM-DD
 * @return string Jalali date in YYYY/MM/DD format
 */
function gregorian_to_jalali($gregorianDate) {
    $parts = explode('-', $gregorianDate);
    if (count($parts) < 3) return $gregorianDate;
    $gy = intval($parts[0]); $gm = intval($parts[1]); $gd = intval($parts[2]);
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intval(($gy2 + 3) / 4) - intval(($gy2 + 99) / 100) + intval(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intval($days / 12053));
    $days %= 12053;
    $jy += 4 * intval($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intval(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intval($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intval(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

/**
 * Convert a Jalali date to Gregorian 'YYYY-MM-DD' (same algorithm family as
 * gregorian_to_jalali). Returns null for invalid input.
 */
function jalali_to_gregorian($jy, $jm, $jd) {
    $jy = (int)$jy; $jm = (int)$jm; $jd = (int)$jd;
    if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 || $jy < 1200 || $jy > 1600) {
        return null;
    }
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $leap = (($gy % 4 == 0) && ($gy % 100 != 0)) || ($gy % 400 == 0);
    $monthDays = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 1; $gm <= 12 && $gd > $monthDays[$gm]; $gm++) {
        $gd -= $monthDays[$gm];
    }
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * Accept 'YYYY-MM-DD' (Gregorian) or Jalali 'YYYY/MM/DD' / 'YYYY-MM-DD' with a
 * 13xx/14xx year (Persian digits allowed). Returns Gregorian 'YYYY-MM-DD' or null.
 */
function normalize_input_date($value) {
    $v = trim(strtr((string)$value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
    if (!preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $v, $m)) {
        return null;
    }
    $y = (int)$m[1];
    if ($y >= 1200 && $y < 1700) {
        return jalali_to_gregorian($y, $m[2], $m[3]);
    }
    $g = sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[3]);
    return validate_date_format($g) ? $g : null;
}

/**
 * Convert Gregorian date (YYYY-MM-DD or YYYY-MM) to exact Jalali date
 *
 * Uses the precise gregorian_to_jalali() algorithm (first day of month
 * when only year-month is given).
 *
 * @param string $gregorianDate Date in format YYYY-MM-DD or YYYY-MM
 * @param bool $includeDay If false, returns only year-month (default: true)
 * @return array ['year' => int, 'month' => int, 'day' => int|null, 'formatted' => string]
 */
function gregorian_to_jalali_approximate($gregorianDate, $includeDay = true) {
    $persianMonthNames = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];

    // Parse date
    $parts = explode('-', $gregorianDate);
    if (count($parts) < 2) {
        return [
            'year' => null,
            'month' => null,
            'day' => null,
            'formatted' => $gregorianDate,
            'error' => 'Invalid date format'
        ];
    }

    $gYear = intval($parts[0]);
    $gMonth = intval($parts[1]);
    $gDay = isset($parts[2]) && $includeDay ? intval($parts[2]) : 1;

    if (!checkdate($gMonth, min(max($gDay, 1), 31), $gYear)) {
        return [
            'year' => null,
            'month' => null,
            'day' => null,
            'formatted' => $gregorianDate,
            'error' => 'Invalid date'
        ];
    }

    $jalali = gregorian_to_jalali(sprintf('%04d-%02d-%02d', $gYear, $gMonth, $gDay));
    $jParts = explode('/', $jalali);
    $jYear = intval($jParts[0] ?? 0);
    $jMonth = intval($jParts[1] ?? 0);
    $jDay = intval($jParts[2] ?? 0);

    // Format output
    $monthName = $persianMonthNames[$jMonth] ?? "ماه {$jMonth}";
    $formatted = $monthName . ' ' . $jYear;

    if ($includeDay) {
        $formatted = $jDay . ' ' . $formatted;
    }

    return [
        'year' => $jYear,
        'month' => $jMonth,
        'day' => $includeDay ? $jDay : null,
        'formatted' => $formatted,
        'monthName' => $monthName
    ];
}

/**
 * Convert Gregorian year-month (YYYY-MM) to Persian month label
 * This is a convenience wrapper around gregorian_to_jalali_approximate
 * 
 * @param string $yearMonth Format: YYYY-MM
 * @return string Persian month label (e.g., "فروردین 1403")
 */
function gregorian_month_to_persian_label($yearMonth) {
    $result = gregorian_to_jalali_approximate($yearMonth, false);
    return $result['formatted'];
}

/**
 * Absolute public base URL of the app (no trailing slash).
 * Works when entry is /hesabdari/api.php or /hesabdari/invoice.php.
 */
function app_public_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($script), '/');
    if (substr($dir, -4) === '/api') {
        $dir = rtrim(dirname($dir), '/');
    }
    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        $dir = '';
    }
    return $scheme . '://' . $host . $dir;
}

/**
 * Short random code for public invoice links (SMS-friendly).
 */
function generate_short_invoice_code($length = 8) {
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/**
 * Build short public invoice URL: .../i.php?c=Ab12Xy9z
 */
function invoice_public_short_url($code) {
    return rtrim(app_public_base_url(), '/') . '/i.php?c=' . rawurlencode($code);
}
