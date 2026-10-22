<?php
/**
 * One-shot schema upgrade for Zeema integration tables/columns.
 * Run once after deploy (CLI only): php api/upgrade_integration.php
 */
// Schema changes must not be triggerable by anyone on the internet.
// The same migrations run automatically (SchemaMigrator) on the first API request.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

$db = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$db->set_charset('utf8mb4');
if ($db->connect_error) {
    die("DB error: " . $db->connect_error . "\n");
}

$messages = [];

function col_exists($db, $table, $col) {
    $table = $db->real_escape_string($table);
    $col = $db->real_escape_string($col);
    $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $res && $res->num_rows > 0;
}

function table_exists($db, $table) {
    $table = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

if (!col_exists($db, 'products', 'fulfillment_mode')) {
    $db->query("ALTER TABLE products ADD COLUMN `fulfillment_mode` enum('stocked','mto') NOT NULL DEFAULT 'stocked'");
    $messages[] = 'Added products.fulfillment_mode';
}

if (!col_exists($db, 'sales_invoices', 'source')) {
    $db->query("ALTER TABLE sales_invoices ADD COLUMN `source` varchar(50) DEFAULT NULL");
    $db->query("ALTER TABLE sales_invoices ADD KEY `idx_source` (`source`)");
    $messages[] = 'Added sales_invoices.source';
}

$tables = [
    'api_tokens' => "CREATE TABLE `api_tokens` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL,
      `token_hash` varchar(64) NOT NULL,
      `token_prefix` varchar(12) NOT NULL,
      `entity_id` int(11) NOT NULL,
      `scopes` text NOT NULL,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `last_used_at` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_token_hash` (`token_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'integration_links' => "CREATE TABLE `integration_links` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `entity_type` varchar(50) NOT NULL,
      `local_id` varchar(64) NOT NULL,
      `remote_id` varchar(128) NOT NULL,
      `remote_system` varchar(50) NOT NULL DEFAULT 'medusa',
      `business_entity_id` int(11) NOT NULL DEFAULT 1,
      `meta_json` longtext DEFAULT NULL,
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_link_remote` (`entity_type`,`remote_system`,`remote_id`),
      UNIQUE KEY `uk_link_local` (`entity_type`,`remote_system`,`business_entity_id`,`local_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'integration_outbox' => "CREATE TABLE `integration_outbox` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `event_type` varchar(80) NOT NULL,
      `payload_json` longtext NOT NULL,
      `idempotency_key` varchar(128) NOT NULL,
      `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
      `attempts` int(11) NOT NULL DEFAULT 0,
      `last_error` text DEFAULT NULL,
      `next_attempt_at` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `sent_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_outbox_idempotency` (`idempotency_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'integration_sso_tokens' => "CREATE TABLE `integration_sso_tokens` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `token_hash` varchar(64) NOT NULL,
      `user_id` int(11) NOT NULL,
      `expires_at` datetime NOT NULL,
      `used_at` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_sso_hash` (`token_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'mto_procurement' => "CREATE TABLE `mto_procurement` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `entity_id` int(11) NOT NULL,
      `remote_order_id` varchar(128) NOT NULL,
      `display_id` varchar(64) DEFAULT NULL,
      `invoice_id` int(11) DEFAULT NULL,
      `product_id` int(11) DEFAULT NULL,
      `title` varchar(255) NOT NULL,
      `dimensions` varchar(100) DEFAULT NULL,
      `quantity` int(11) NOT NULL DEFAULT 1,
      `status` enum('open','ordered','received','fulfilled','cancelled') NOT NULL DEFAULT 'open',
      `meta_json` longtext DEFAULT NULL,
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_mto_entity_status` (`entity_id`,`status`),
      KEY `idx_mto_remote` (`remote_order_id`),
      KEY `idx_mto_invoice` (`invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    if (!table_exists($db, $name)) {
        if ($db->query($sql)) {
            $messages[] = "Created table {$name}";
        } else {
            $messages[] = "ERROR creating {$name}: " . $db->error;
        }
    } else {
        $messages[] = "Table {$name} already exists";
    }
}

echo "Integration upgrade complete\n";
echo implode("\n", $messages) . "\n";
echo "Next: create API token from hesabdari session (action integration_create_api_token) or SQL.\n";
$db->close();
