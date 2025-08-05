<?php
// /api.php
/**
 * Entry point for all API requests.
 * This ensures backward compatibility with the frontend while using the new modular structure.
 * It forwards the request to the new router inside the /api/ directory.
 */
require_once __DIR__ . '/api/index.php';