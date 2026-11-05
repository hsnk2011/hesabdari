<?php
// Never display errors to clients, but log everything (error_reporting is re-asserted
// in api/index.php; this keeps the wrapper consistent with that production posture).
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
// /api.php
/**
 * Entry point for all API requests.
 * This ensures backward compatibility with the frontend while using the new modular structure.
 * It forwards the request to the new router inside the /api/ directory.
 */
require_once __DIR__ . '/api/index.php';