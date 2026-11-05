<?php
// Copy this file to config.php and set your database credentials.
// config.php is ignored by git — never commit real credentials.

define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'your_db_user');
define('DB_PASSWORD', 'your_db_password');
define('DB_NAME', 'your_db_name');

date_default_timezone_set('Asia/Tehran');

// --- Zeema Home / Medusa integration (optional) ---
// Shared HMAC secret for outbound webhooks to Medusa
define('HESABDARI_INTEGRATION_SECRET', 'change-me-to-a-long-random-secret');
// Medusa webhook endpoint, e.g. https://api.example.com/hooks/hesabdari
define('MEDUSA_WEBHOOK_URL', '');
// Default business entity for online store
define('DEFAULT_ENTITY_ID', 1);
// Medusa stores IRR (rial); hesabdari typically uses toman → divide by 10
define('INTEGRATION_RIAL_TO_ACCOUNTING_DIVISOR', 10);

// Local rembg service for product image background removal (same as Zeema Home)
define('REMBG_URL', 'http://127.0.0.1:7001');
define('REMBG_MODEL', 'isnet-general-use');
