<?php
/**
 * Real cron entry point (recommended over the request-triggered fallback).
 *
 *   CLI (every minute):   * * * * * php /path/to/hesabdari/cron.php
 *   HTTP (cron service):  https://example.com/hesabdari/cron.php?secret=AUTO_BACKUP_SECRET
 *
 * After it works, set «حالت اجرای کارهای زمان‌بندی‌شده» to «کران خارجی» in
 * Settings so normal requests stop triggering jobs.
 *
 * Options (CLI): --force   run every task now, ignoring intervals
 *                --backup  also take the daily automatic backup if it is due
 */
$isCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/core/constants.php';
require_once __DIR__ . '/api/core/helpers.php';
require_once __DIR__ . '/api/core/AppLogger.php';
require_once __DIR__ . '/api/core/Scheduler.php';
require_once __DIR__ . '/api/core/SchemaMigrator.php';

AppLogger::init(['dir' => __DIR__ . '/logs']);

$db = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$db->set_charset('utf8mb4');
db_sync_timezone($db);

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $secret = (string)($_GET['secret'] ?? '');
    $expected = (string)get_app_setting($db, 'auto_backup_secret', '');
    $ok = $secret !== '' && (
        ($expected !== '' && hash_equals($expected, $secret))
        || (defined('CRON_SECRET_KEY') && hash_equals((string)CRON_SECRET_KEY, $secret))
    );
    if (!$ok) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

$args = $isCli ? array_slice($argv, 1) : array_keys($_GET);
$force = in_array('--force', $args, true) || in_array('force', $args, true);
$withBackup = in_array('--backup', $args, true) || in_array('backup', $args, true)
    || get_app_setting($db, 'cron_mode', 'request') === 'external';

if (session_status() === PHP_SESSION_NONE) {
    $_SESSION = [];
}

SchemaMigrator::ensureUpToDate($db);
$report = Scheduler::tick($force);

if ($withBackup) {
    $enabled = get_app_setting($db, 'auto_backup_enabled', '0') === '1';
    $last = (int)get_app_setting($db, 'last_auto_backup_timestamp', 0);
    if ($enabled && $last < time() - 86400) {
        require_once __DIR__ . '/api/controllers/BackupController.php';
        $bc = new BackupController($db);
        $includeImages = get_app_setting($db, 'auto_backup_include_images', '0') === '1';
        $res = $bc->runAutomaticBackupInternal($includeImages);
        if (!empty($res['success'])) {
            $now = (string)time();
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('last_auto_backup_timestamp', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->bind_param('s', $now);
            $stmt->execute();
            $stmt->close();
        }
        $report['auto_backup'] = $res;
    }
}

echo json_encode(['ran_at' => date('Y-m-d H:i:s'), 'report' => $report], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
$db->close();
