<?php
// /api/core/SchemaMigrator.php

/**
 * Brings the database schema up to date automatically, once per APP_VERSION.
 *
 * Before this existed, schema changes were applied either by running
 * setup.php?upgrade=1 by hand or by models issuing ALTER TABLE lazily in the
 * middle of a request — often inside an open transaction, where DDL forces an
 * implicit COMMIT and silently breaks atomicity of invoice/payment saves.
 * Running every migration here, at the start of the first request after a
 * deploy and outside any transaction, removes both problems.
 */
class SchemaMigrator {
    const VERSION_KEY = 'schema_app_version';
    const LOCK_NAME = 'hesabdari_schema_migration';

    /**
     * Models whose (private) ensure* helpers still create tables/columns lazily.
     * They are executed here once so that the lazy path never needs DDL later.
     * [file (relative to api/models), class, method, constructor needs type arg]
     */
    private static $modelEnsures = [
        ['Payment.php', 'Payment', 'ensureAllocatedColumn'],
        ['Invoice.php', 'Invoice', 'ensureReturnedFromSalesColumn'],
        ['Invoice.php', 'Invoice', 'ensurePurchaseWarehouseColumn'],
        ['Invoice.php', 'Invoice', 'ensureWarehouseTypeColumn'],
        ['ReturnInvoice.php', 'ReturnInvoice', 'ensureReturnTablesExist'],
        ['BankImport.php', 'BankImport', 'ensureMatchedSourceSupportsIncome'],
        ['BankImport.php', 'BankImport', 'ensureMatchHistoryCounterpartyColumn'],
        ['BankImport.php', 'BankImport', 'ensureMatchHistoryTable'],
        ['BankImport.php', 'BankImport', 'ensureBankFormatSupportsMelli'],
        ['BankImport.php', 'BankImport', 'ensureBankFormatSupportsBlubank'],
        ['InvoiceHistory.php', 'InvoiceHistory', 'ensureTable'],
        ['OnlinePayment.php', 'OnlinePayment', 'ensureTable'],
        ['SmsLog.php', 'SmsLog', 'ensureTable'],
        ['EntitySettings.php', 'EntitySettings', 'ensureTable'],
        ['UserSettings.php', 'UserSettings', 'ensureTable'],
        ['PriceList.php', 'PriceList', 'ensureUpdatedAtColumns'],
        ['Partner.php', 'Partner', 'ensurePhoneColumn'],
        ['Customer.php', 'Customer', 'ensurePhoneColumnWidth'],
        ['Supplier.php', 'Supplier', 'ensurePhoneColumnWidth'],
        ['Contact.php', 'Contact', 'ensurePhoneColumnWidth'],
        ['AuditRun.php', 'AuditRun', 'ensureSchema'],
    ];

    /**
     * Fast path: one indexed SELECT per request. Runs the migration only when
     * the stored version differs from APP_VERSION.
     */
    public static function ensureUpToDate(mysqli $conn) {
        if (!defined('APP_VERSION')) {
            return;
        }
        $current = null;
        try {
            $res = $conn->query("SELECT setting_value FROM settings WHERE setting_key = '" . self::VERSION_KEY . "' LIMIT 1");
            if ($res && ($row = $res->fetch_assoc())) {
                $current = $row['setting_value'];
            }
        } catch (Throwable $e) {
            // settings table missing: let setup.php handle a fresh install.
            return;
        }
        if ($current === APP_VERSION) {
            return;
        }
        self::run($conn);
    }

    public static function run(mysqli $conn, array &$messages = []) {
        $lockRes = $conn->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0) AS l");
        $locked = $lockRes && ($r = $lockRes->fetch_assoc()) && (int)$r['l'] === 1;
        if (!$locked) {
            // Another request is migrating; continue with the current schema.
            return false;
        }
        try {
            require_once __DIR__ . '/schema.php';
            @set_time_limit(300);
            $pdo = new PDO(
                'mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USERNAME,
                DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $error = '';
            synchronize_schema($pdo, DB_NAME, $messages, $error);
            migrate_stock_data($pdo, DB_NAME, $messages, $error);
            repair_partner_share_history($pdo, DB_NAME, $messages);
            seed_default_data($pdo, $messages);
            run_versioned_data_migrations($pdo, DB_NAME, $messages);

            // Every permission belongs to the system administrator role.
            try {
                $pdo->exec(
                    "INSERT IGNORE INTO role_permissions (role_id, permission_id)
                     SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.is_system = 1"
                );
            } catch (PDOException $e) { /* older schema without is_system */ }

            self::runModelEnsures($conn);

            if ($error !== '' && class_exists('AppLogger')) {
                AppLogger::error('Schema migration finished with errors', ['source' => 'schema_migrator', 'errors' => strip_tags($error)]);
            }
            $stmt = $conn->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $key = self::VERSION_KEY;
            $ver = APP_VERSION;
            $stmt->bind_param('ss', $key, $ver);
            $stmt->execute();
            $stmt->close();
            if (class_exists('AppLogger')) {
                AppLogger::info('Schema migrated to ' . APP_VERSION, ['source' => 'schema_migrator', 'changes' => count($messages)]);
            }
            return true;
        } catch (Throwable $e) {
            error_log('SchemaMigrator failed: ' . $e->getMessage());
            if (class_exists('AppLogger')) {
                AppLogger::exception($e, null, ['source' => 'schema_migrator']);
            }
            return false;
        } finally {
            $conn->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    private static function runModelEnsures(mysqli $conn) {
        foreach (self::$modelEnsures as $def) {
            list($file, $class, $method) = $def;
            try {
                $path = __DIR__ . '/../models/' . $file;
                if (!file_exists($path)) {
                    continue;
                }
                require_once $path;
                if (!class_exists($class) || !method_exists($class, $method)) {
                    continue;
                }
                $obj = new $class($conn);
                $rm = new ReflectionMethod($class, $method);
                $rm->setAccessible(true);
                $rm->invoke($obj);
            } catch (Throwable $e) {
                error_log("SchemaMigrator ensure {$class}::{$method} failed: " . $e->getMessage());
            }
        }
    }
}
