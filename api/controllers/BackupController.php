<?php
// /api/controllers/BackupController.php
require_once __DIR__ . '/../core/RemoteBackup.php';
require_once __DIR__ . '/../core/BackupEmail.php';

class BackupController {
    private $conn;
    private $backupDir;
    private $allowedPartialTables = [
        'customers', 'suppliers', 'products', 'inventory_items',
        'sales_invoices', 'sales_invoice_items',
        'purchase_invoices', 'purchase_invoice_items',
        'checks', 'payments', 'expenses', 'incomes', 'partners', 'accounts'
    ];

    public function __construct($db) {
        $this->conn = $db;
        $this->backupDir = __DIR__ . '/../../backups/';
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    private function getDbCredentials() {
        return ['host' => DB_SERVER, 'user' => DB_USERNAME, 'pass' => DB_PASSWORD, 'name' => DB_NAME];
    }

    private function collectBackupFilesFromDir($dir, $filenamePrefix = '') {
        $backups = [];
        if (!is_dir($dir)) {
            return $backups;
        }

        $files = scandir($dir, SCANDIR_SORT_DESCENDING);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $isSqlGz = (pathinfo($file, PATHINFO_EXTENSION) === 'gz' && strpos($file, '.sql') !== false);
            $isZip = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'zip';
            $isEnc = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'enc';
            if ((!$isSqlGz && !$isZip && !$isEnc) || strpos($file, 'tmp_') === 0) {
                continue;
            }
            $filePath = $dir . $file;
            $type = 'نامشخص';
            if (strpos($file, 'remote_') === 0) {
                $type = 'دریافتی از سرور دیگر';
            } else if (strpos($file, 'full_') === 0 || strpos($file, 'auto_full_') === 0) {
                $type = 'کامل';
            } else if (strpos($file, 'entity_') === 0) {
                preg_match('/entity_(\d+)_/', $file, $matches);
                $type = 'مجموعه کاری #' . ($matches[1] ?? '');
            } else if (strpos($file, 'partial_') === 0) {
                $type = 'جزئی (بخش خاص)';
            } else if (strpos($file, 'pre_restore_') === 0) {
                $type = 'نقطه بازگشت (پیش از بازیابی)';
            }
            if ($isEnc) {
                $type .= ' (رمزنگاری‌شده)';
            }
            if ($isZip || strpos($file, 'with_images') !== false) {
                $type .= ' + تصاویر';
            }

            $backups[] = [
                'filename' => $filenamePrefix . $file,
                'date' => date("Y-m-d H:i:s", filemtime($filePath)),
                'size' => filesize($filePath),
                'type' => $type,
                'has_images' => $isZip || strpos($file, 'with_images') !== false,
            ];
        }
        return $backups;
    }

    private function resolveBackupPath($filename) {
        $filename = str_replace('\\', '/', (string)$filename);
        $isRemote = strpos($filename, 'remote/') === 0;
        $base = $isRemote ? substr($filename, strlen('remote/')) : $filename;
        if (basename($base) !== $base || strpos($base, '..') !== false) {
            return null;
        }
        if (!$this->isValidBackupFilename($isRemote ? ('remote/' . $base) : $base)) {
            return null;
        }
        return $isRemote ? ($this->backupDir . 'remote/' . $base) : ($this->backupDir . $base);
    }

    private function isValidBackupFilename($filename) {
        return (bool)preg_match('/^(remote\/)?[a-zA-Z0-9][a-zA-Z0-9_\.\-]*\.(sql(\.gz)?|zip|enc)$/i', $filename);
    }

    private function iniSizeToBytes($value) {
        $value = trim((string)$value);
        if ($value === '' || $value === '0') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $num = (float)$value;
        switch ($unit) {
            case 'g':
                return (int)($num * 1024 * 1024 * 1024);
            case 'm':
                return (int)($num * 1024 * 1024);
            case 'k':
                return (int)($num * 1024);
            default:
                return (int)$num;
        }
    }

    private function productsUploadDir() {
        return realpath(__DIR__ . '/../../uploads/products') ?: (__DIR__ . '/../../uploads/products');
    }

    private function addDirectoryToZip(ZipArchive $zip, $dir, $zipPrefix) {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = $zipPrefix . '/' . ltrim(str_replace('\\', '/', substr($path, strlen($dir))), '/');
            if ($file->isDir()) {
                $zip->addEmptyDir(rtrim($relative, '/'));
            } else {
                $zip->addFile($path, $relative);
                $count++;
            }
        }
        return $count;
    }

    private function wrapBackupWithImages($sqlFilename) {
        if (!class_exists('ZipArchive')) {
            return ['error' => 'افزونه ZipArchive روی سرور فعال نیست؛ پشتیبان با تصاویر ممکن نیست.'];
        }
        $sqlPath = $this->backupDir . $sqlFilename;
        if (!file_exists($sqlPath)) {
            return ['error' => 'فایل پشتیبان دیتابیس یافت نشد.'];
        }
        $zipFilename = preg_replace('/\.sql\.gz$/i', '', $sqlFilename) . '.with_images.zip';
        $zipPath = $this->backupDir . $zipFilename;
        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            return ['error' => 'امکان ایجاد فایل ZIP پشتیبان وجود ندارد.'];
        }
        if (!is_readable($sqlPath) || filesize($sqlPath) === 0) {
            $zip->close();
            @unlink($zipPath);
            return ['error' => 'خواندن فایل پشتیبان دیتابیس برای قرار دادن در ZIP ناموفق بود.'];
        }
        // addFile streams from disk at close() (the dump is already compressed).
        $zip->addFile($sqlPath, 'database.sql.gz');
        if (method_exists($zip, 'setCompressionName')) {
            $zip->setCompressionName('database.sql.gz', ZipArchive::CM_STORE);
        }
        $manifest = json_encode([
            'version' => 1,
            'include_images' => true,
            'created_at' => date('c'),
            'sql_file' => 'database.sql.gz',
        ], JSON_UNESCAPED_UNICODE);
        $zip->addFromString('manifest.json', $manifest);

        $productsDir = $this->productsUploadDir();
        $fileCount = 0;
        if (is_dir($productsDir)) {
            $fileCount = $this->addDirectoryToZip($zip, $productsDir, 'uploads/products');
        }
        if ($zip->close() !== true) {
            @unlink($zipPath);
            return ['error' => 'بستن فایل ZIP پشتیبان ناموفق بود.'];
        }
        @unlink($sqlPath);

        return [
            'success' => true,
            'filename' => $zipFilename,
            'image_files' => $fileCount,
        ];
    }

    private function extractZipBackup($zipPath) {
        if (!class_exists('ZipArchive')) {
            throw new Exception('افزونه ZipArchive روی سرور فعال نیست.');
        }
        $tmpDir = $this->backupDir . 'tmp_restore_' . uniqid() . '/';
        if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new Exception('امکان ایجاد پوشه موقت بازیابی وجود ندارد.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->rrmdir($tmpDir);
            throw new Exception('باز کردن فایل ZIP ناموفق بود.');
        }
        $zip->extractTo($tmpDir);
        $zip->close();

        $sqlPath = $this->findSqlInExtractedBackup($tmpDir);
        if (!$sqlPath) {
            $this->rrmdir($tmpDir);
            throw new Exception('در فایل ZIP پشتیبان، دیتابیس یافت نشد.');
        }
        return ['tmpDir' => $tmpDir, 'sqlPath' => $sqlPath];
    }

    private function findSqlInExtractedBackup($tmpDir) {
        $manifestPath = $tmpDir . 'manifest.json';
        if (is_file($manifestPath)) {
            $manifest = json_decode((string)file_get_contents($manifestPath), true);
            $sqlFile = $manifest['sql_file'] ?? '';
            if (is_string($sqlFile) && $sqlFile !== '' && strpos($sqlFile, '..') === false) {
                $candidate = $tmpDir . ltrim(str_replace('\\', '/', $sqlFile), '/');
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        foreach (['database.sql.gz', 'database.sql'] as $candidate) {
            if (is_file($tmpDir . $candidate)) {
                return $tmpDir . $candidate;
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (preg_match('/\.sql(\.gz)?$/i', $name)) {
                return $file->getPathname();
            }
        }
        return null;
    }

    private function restoreProductImagesFromDir($extractedRoot) {
        $src = $extractedRoot . 'uploads/products';
        if (!is_dir($src)) {
            return 0;
        }
        $dest = __DIR__ . '/../../uploads/products';
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($src))), '/');
            $target = $dest . '/' . $relative;
            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                if (@copy($file->getPathname(), $target)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function rrmdir($dir) {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) $this->rrmdir($path);
            else @unlink($path);
        }
        @rmdir($dir);
    }

    public function listBackups() {
        check_permission('manage_settings');
        $backups = $this->collectBackupFilesFromDir($this->backupDir);
        $remoteBackups = $this->collectBackupFilesFromDir($this->backupDir . 'remote/', 'remote/');
        $backups = array_merge($backups, $remoteBackups);

        // Users without access to every entity see only their entities' backups.
        $allowedEntities = array_map('intval', $_SESSION['allowed_entities'] ?? []);
        if (!user_has_all_entities($this->conn, $allowedEntities)) {
            $backups = array_values(array_filter($backups, function ($b) use ($allowedEntities) {
                $name = basename((string)($b['filename'] ?? $b['name'] ?? ''));
                return preg_match('/^entity_(\d+)_/', $name, $m) && in_array((int)$m[1], $allowedEntities, true);
            }));
        }

        usort($backups, function ($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
        });

        send_json($backups);
    }
    
    private function writeSqlHeader($gz) {
        gzwrite($gz, "-- Hesabdari Backup | " . date('Y-m-d H:i:s') . "\n");
        gzwrite($gz, "SET NAMES utf8mb4;\n");
        gzwrite($gz, "SET time_zone = '+00:00';\n");
        gzwrite($gz, "SET foreign_key_checks = 0;\n\n");
    }

    private function writeSqlFooter($gz) {
        gzwrite($gz, "SET foreign_key_checks = 1;\n");
    }

    private function writeTableSql($gz, $table, $whereClause = '1', $dropTable = true) {
        if ($dropTable) {
            $create_table_result = $this->conn->query("SHOW CREATE TABLE `{$table}`");
            $create_table_row = $create_table_result->fetch_assoc();
            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
            gzwrite($gz, $create_table_row['Create Table'] . ";\n\n");
            $create_table_result->free();
        } else {
            // Entity backups must not drop/recreate shared tables. Only clear the
            // rows matching this entity so a restore replaces just that data.
            gzwrite($gz, "DELETE FROM `{$table}` WHERE {$whereClause};\n");
        }

        $data_result = $this->conn->query("SELECT * FROM `{$table}` WHERE {$whereClause}");
        if ($data_result && $data_result->num_rows > 0) {
            while ($data_row = $data_result->fetch_assoc()) {
                $keys = array_keys($data_row);
                $values = array_map(function($value) {
                    if ($value === null) return 'NULL';
                    return "'" . $this->conn->real_escape_string($value) . "'";
                }, array_values($data_row));
                gzwrite($gz, "INSERT INTO `{$table}` (`" . implode('`,`', $keys) . "`) VALUES (" . implode(',', $values) . ");\n");
            }
        }
        gzwrite($gz, "\n");
        if($data_result) $data_result->free();
    }

    private function createBackupWithPHP($filename, $type = 'full', $options = []) {
        // PHP-based dump (shared hosting has no mysqldump). Rows are streamed
        // straight into the gzip file, so memory stays flat.
        $filePath = $this->backupDir . $filename;
        // TIMESTAMP columns are dumped in UTC and the header restores with
        // time_zone=+00:00; without this, every backup/restore cycle shifted
        // created_at/updated_at values by the server offset (+03:30).
        $this->conn->query("SET time_zone = '+00:00'");
        try {
            $gz = gzopen($filePath, 'w9');
            if (!$gz) throw new Exception("امکان ایجاد فایل پشتیبان وجود ندارد. لطفا سطح دسترسی پوشه `backups/` را بررسی کنید.");

            $this->writeSqlHeader($gz);

            if ($type === 'full') {
                $tables_result = $this->conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
                while ($row = $tables_result->fetch_row()) $this->writeTableSql($gz, $row[0]);
                $tables_result->free();
            } elseif ($type === 'entity' && !empty($options['entityId'])) {
                $entityId = intval($options['entityId']);
                $entityTables = [];
                $all_tables_res = $this->conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
                while ($row = $all_tables_res->fetch_row()) {
                    $table = $row[0];
                    $cols_res = $this->conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'entity_id'");
                    if ($cols_res && $cols_res->num_rows > 0) {
                        $entityTables[] = $table;
                    }
                }
                foreach ($entityTables as $table) {
                    $this->writeTableSql($gz, $table, "`entity_id` = {$entityId}", false);
                }
                // Child tables without entity_id (invoice lines, images, ...) are
                // included through their foreign key to an entity-scoped parent.
                foreach ($this->entityChildTables($entityTables) as $child) {
                    $where = "`{$child['column']}` IN (SELECT `{$child['ref_column']}` FROM `{$child['parent']}` WHERE `entity_id` = {$entityId})";
                    $this->writeTableSql($gz, $child['table'], $where, false);
                }
            } elseif ($type === 'partial' && !empty($options['tables'])) {
                foreach ($options['tables'] as $table) {
                    if (in_array($table, $this->allowedPartialTables)) {
                        $this->writeTableSql($gz, $table);
                    }
                }
            }

            $this->writeSqlFooter($gz);
            gzclose($gz);
            return ['success' => true];
        } catch (Exception $e) {
            if (isset($gz) && $gz) gzclose($gz);
            if (file_exists($filePath)) @unlink($filePath);
            return ['error' => $e->getMessage()];
        } finally {
            db_sync_timezone($this->conn);
        }
    }

    /**
     * Tables without entity_id that reference an entity-scoped table through a
     * foreign key, or through the known logical links used by this app.
     */
    private function entityChildTables(array $entityTables) {
        $children = [];
        $res = $this->conn->query(
            "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL"
        );
        $seen = [];
        while ($res && ($r = $res->fetch_assoc())) {
            if (in_array($r['TABLE_NAME'], $entityTables, true) || !in_array($r['REFERENCED_TABLE_NAME'], $entityTables, true)) {
                continue;
            }
            $key = $r['TABLE_NAME'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $children[] = ['table' => $r['TABLE_NAME'], 'column' => $r['COLUMN_NAME'], 'parent' => $r['REFERENCED_TABLE_NAME'], 'ref_column' => $r['REFERENCED_COLUMN_NAME']];
        }
        $logical = [
            ['sales_invoice_items', 'invoiceId', 'sales_invoices'],
            ['purchase_invoice_items', 'invoiceId', 'purchase_invoices'],
            ['quotation_items', 'quotationId', 'quotations'],
            ['return_invoice_items', 'return_invoice_id', 'return_invoices'],
            ['product_images', 'product_id', 'products'],
            ['stocktaking_items', 'stocktaking_id', 'stocktaking'],
            ['bank_import_items', 'import_id', 'bank_imports'],
        ];
        foreach ($logical as $l) {
            if (isset($seen[$l[0]]) || in_array($l[0], $entityTables, true) || !in_array($l[2], $entityTables, true)) {
                continue;
            }
            $exists = $this->conn->query("SHOW COLUMNS FROM `{$l[0]}` LIKE '{$l[1]}'");
            if ($exists && $exists->num_rows > 0) {
                $seen[$l[0]] = true;
                $children[] = ['table' => $l[0], 'column' => $l[1], 'parent' => $l[2], 'ref_column' => 'id'];
            }
        }
        return $children;
    }

    /** Password used to encrypt backups leaving the server ('' = disabled). */
    private function encryptionPassword() {
        return (string)get_app_setting($this->conn, 'backup_encryption_password', '');
    }

    /**
     * Copy to send off-site (remote server / e-mail): encrypted when a backup
     * password is configured. Returns [path, filename, isTemp].
     */
    private function offsiteCopy($filePath, $filename) {
        $password = $this->encryptionPassword();
        if ($password === '') {
            return [$filePath, $filename, false];
        }
        require_once __DIR__ . '/../core/BackupCrypto.php';
        $encName = $filename . '.enc';
        $encPath = $this->backupDir . 'tmp_' . uniqid() . '_' . $encName;
        BackupCrypto::encryptFile($filePath, $encPath, $password);
        return [$encPath, $encName, true];
    }

    /**
     * Execute a (possibly gzip-compressed) SQL dump statement by statement,
     * without loading the whole file into memory. Statements are batched into
     * multi_query calls of ~2 MB; every result is checked for errors.
     */
    private function executeSqlStream($path) {
        $fh = gzopen($path, 'rb'); // gzopen also reads plain .sql transparently
        if (!$fh) {
            throw new Exception('امکان خواندن فایل پشتیبان وجود ندارد.');
        }
        $batch = '';
        $stmt = '';
        $count = 0;
        $flush = function () use (&$batch) {
            if ($batch === '') {
                return;
            }
            if (!$this->conn->multi_query($batch)) {
                throw new Exception("خطا در اجرای اسکریپت بازیابی: " . $this->conn->error);
            }
            do {
                if ($this->conn->errno) {
                    throw new Exception("خطا در اجرای اسکریپت بازیابی: " . $this->conn->error);
                }
                if ($result = $this->conn->store_result()) {
                    $result->free();
                }
                if (!$this->conn->more_results()) {
                    break;
                }
            } while ($this->conn->next_result());
            if ($this->conn->errno) {
                throw new Exception("خطا در اجرای اسکریپت بازیابی: " . $this->conn->error);
            }
            $batch = '';
        };
        while (!gzeof($fh)) {
            $line = gzgets($fh, 16 * 1024 * 1024);
            if ($line === false) {
                break;
            }
            if ($stmt === '') {
                $trim = ltrim($line);
                if ($trim === '' || strpos($trim, '--') === 0 || strpos($trim, '/*') === 0 && substr(rtrim($trim), -3) === '*/;' && stripos($trim, '/*!') !== 0) {
                    continue;
                }
            }
            $stmt .= $line;
            if (substr(rtrim($line), -1) === ';') {
                $batch .= $stmt;
                $stmt = '';
                $count++;
                if (strlen($batch) > 2 * 1024 * 1024) {
                    $flush();
                }
            }
        }
        gzclose($fh);
        if (trim($stmt) !== '') {
            $batch .= $stmt;
        }
        $flush();
        return $count;
    }

    /** Tables and row counts inside a dump, for the restore preview. */
    private function inspectSqlStream($path) {
        $fh = gzopen($path, 'rb');
        if (!$fh) {
            throw new Exception('امکان خواندن فایل پشتیبان وجود ندارد.');
        }
        $tables = [];
        $header = null;
        $lines = 0;
        while (!gzeof($fh)) {
            $line = gzgets($fh, 16 * 1024 * 1024);
            if ($line === false) break;
            $lines++;
            if ($header === null && $lines <= 3 && strpos($line, '-- Hesabdari Backup') === 0) {
                $header = trim(substr($line, 3));
            }
            if (preg_match('/^INSERT INTO `([^`]+)`/', $line, $m)) {
                $tables[$m[1]] = ($tables[$m[1]] ?? 0) + substr_count($line, '),(') + 1;
            } elseif (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
                $tables[$m[1]] = $tables[$m[1]] ?? 0;
            } elseif (preg_match('/^DELETE FROM `([^`]+)`/', $line, $m)) {
                $tables[$m[1]] = $tables[$m[1]] ?? 0;
            }
        }
        gzclose($fh);
        ksort($tables);
        return ['header' => $header, 'tables' => $tables];
    }

    /** Resolve an uploaded/stored backup into a plain .sql/.sql.gz path (+ extracted dir). */
    private function prepareRestoreSource($sourcePath, $originalFilename, $password = null) {
        $tmpFiles = [];
        $tmpDir = null;
        $name = $originalFilename;
        if (preg_match('/\.enc$/i', $name) || BackupCrypto::isEncryptedFile($sourcePath)) {
            $password = ($password !== null && $password !== '') ? $password : $this->encryptionPassword();
            if ($password === '') {
                throw new Exception('این پشتیبان رمزنگاری شده است؛ رمز پشتیبان را وارد کنید.');
            }
            $name = preg_replace('/\.enc$/i', '', $name);
            $plain = $this->backupDir . 'tmp_' . uniqid() . '_' . basename($name);
            BackupCrypto::decryptFile($sourcePath, $plain, $password);
            $tmpFiles[] = $plain;
            $sourcePath = $plain;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            $extracted = $this->extractZipBackup($sourcePath);
            $tmpDir = $extracted['tmpDir'];
            $sourcePath = $extracted['sqlPath'];
            $name = basename($sourcePath);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        }
        if (!in_array($ext, ['sql', 'gz'], true)) {
            foreach ($tmpFiles as $f) @unlink($f);
            if ($tmpDir) $this->rrmdir($tmpDir);
            throw new Exception('فرمت فایل پشتیبان باید sql، sql.gz، zip یا enc باشد.');
        }
        return ['sqlPath' => $sourcePath, 'tmpDir' => $tmpDir, 'tmpFiles' => $tmpFiles];
    }

    private function appendEmailBackupResult(array &$response, $filePath, $filename, $context) {
        $emailResult = BackupEmail::sendBackupFile($this->conn, $filePath, $filename, $context);
        if (!empty($emailResult['success'])) {
            $response['email_backup'] = 'success';
            $response['email_backup_attached'] = !empty($emailResult['attached']);
            $response['email_backup_message'] = $emailResult['message'] ?? 'ارسال ایمیل انجام شد.';
            $emailFile = $emailResult['email_filename'] ?? $filename;
            $storedFile = $emailResult['stored_filename'] ?? $filename;
            $logDetail = "پشتیبان {$emailFile} به ایمیل ارسال شد (پیوست)";
            if ($storedFile !== $emailFile) {
                $logDetail .= "؛ ZIP کامل روی سرور: {$storedFile}";
            }
            log_activity($this->conn, 'BACKUP_EMAIL_SENT', $logDetail . '.');
        } elseif (!empty($emailResult['skipped'])) {
            $response['email_backup'] = 'skipped';
        } elseif (!empty($emailResult['error'])) {
            $response['email_backup'] = 'failed';
            $response['email_backup_error'] = $emailResult['error'];
            log_activity($this->conn, 'BACKUP_EMAIL_FAILED', "ارسال ایمیل پشتیبان {$filename} ناموفق: {$emailResult['error']}");
        }
    }

    public function createBackup($data) {
        check_permission('manage_settings');
        $type = $data['type'] ?? 'full';
        $includeImages = !empty($data['include_images']) && ($data['include_images'] === true || $data['include_images'] === 1 || $data['include_images'] === '1');
        $options = [];
        $filename = "{$type}_";

        if ($type === 'entity') {
            $entityId = intval($data['entityId'] ?? 0);
            if ($entityId <= 0) {
                send_json(['error' => 'شناسه مجموعه کاری نامعتبر است.'], 400); return;
            }
            $options['entityId'] = $entityId;
            $filename .= "{$entityId}_";
        } elseif ($type === 'partial') {
            $tables = $data['tables'] ?? [];
            if (empty($tables) || !is_array($tables)) {
                send_json(['error' => 'حداقل یک بخش برای پشتیبان‌گیری انتخاب کنید.'], 400); return;
            }
            $options['tables'] = array_intersect($tables, $this->allowedPartialTables);
            if (empty($options['tables'])) {
                 send_json(['error' => 'بخش‌های انتخاب شده برای پشتیبان‌گیری نامعتبر هستند.'], 400); return;
            }
        }
        
        $filename .= date('Y-m-d_H-i-s') . '.sql.gz';
        $result = $this->createBackupWithPHP($filename, $type, $options);
        
        if (isset($result['success'])) {
            $finalFilename = $filename;
            $imageFiles = 0;
            if ($includeImages) {
                $wrapped = $this->wrapBackupWithImages($filename);
                if (isset($wrapped['error'])) {
                    send_json(['error' => $wrapped['error']], 500);
                    return;
                }
                $finalFilename = $wrapped['filename'];
                $imageFiles = intval($wrapped['image_files'] ?? 0);
            }

            $filePath = $this->backupDir . $finalFilename;
            list($offPath, $offName, $offTemp) = $this->offsiteCopy($filePath, $finalFilename);
            $remoteResult = RemoteBackup::uploadBackupFile($this->conn, $offPath, $offName);

            log_activity($this->conn, 'BACKUP_CREATED', "پشتیبان ({$type}) با نام {$finalFilename} ایجاد شد" . ($includeImages ? ' (با تصاویر)' : '') . ".");
            $response = ['success' => true, 'filename' => $finalFilename, 'include_images' => $includeImages, 'image_files' => $imageFiles];
            if (!empty($remoteResult['success'])) {
                $response['remote_backup'] = 'success';
                log_activity($this->conn, 'REMOTE_BACKUP_SENT', "پشتیبان {$finalFilename} به سرور دیگر ارسال شد.");
            } elseif (!empty($remoteResult['error'])) {
                $response['remote_backup'] = 'failed';
                $response['remote_backup_error'] = $remoteResult['error'];
                log_activity($this->conn, 'REMOTE_BACKUP_FAILED', "ارسال پشتیبان {$finalFilename} به سرور دیگر ناموفق بود: {$remoteResult['error']}");
            }

            $this->appendEmailBackupResult($response, $offPath, $offName, 'manual');
            if ($offTemp) {
                @unlink($offPath);
            }

            send_json($response);
        } else {
            send_json(['error' => $result['error']], 500);
        }
    }
    
    public function deleteBackup($data) {
        check_permission('manage_settings');
        $filename = $data['filename'] ?? '';
        if (empty($filename)) {
            send_json(['error' => 'نام فایل نامعتبر است.'], 400);
            return;
        }

        $filePath = $this->resolveBackupPath($filename);
        if ($filePath === null || !$this->isValidBackupFilename($filename)) {
            send_json(['error' => 'نام فایل نامعتبر است.'], 400);
            return;
        }

        $realBackupDir = realpath($this->backupDir);
        $realFilePath = realpath($filePath);
        if ($realFilePath === false || $realBackupDir === false || strpos($realFilePath, $realBackupDir) !== 0) {
            send_json(['error' => 'فایل در مسیر مجاز قرار ندارد.'], 400);
            return;
        }

        if (file_exists($realFilePath)) {
            if (unlink($realFilePath)) {
                log_activity($this->conn, 'BACKUP_DELETED', "فایل پشتیبان {$filename} حذف شد.");
                send_json(['success' => true]);
            } else {
                send_json(['error' => 'خطا در حذف فایل پشتیبان.'], 500);
            }
        } else {
            send_json(['error' => 'فایل مورد نظر یافت نشد.'], 404);
        }
    }

    private function performRestore($sourcePath, $originalFilename, $restoreImages = true, $password = null) {
        if (strpos(basename($originalFilename), 'entity_') === 0) {
            throw new Exception('پشتیبان‌های مجموعه کاری (entity) قابل بازیابی از طریق بازیابی کامل نیستند، زیرا این کار مخرب است. لطفاً از پشتیبان کامل استفاده کنید.');
        }
        require_once __DIR__ . '/../core/BackupCrypto.php';
        @set_time_limit(0);
        @ignore_user_abort(true);

        $prep = $this->prepareRestoreSource($sourcePath, $originalFilename, $password);
        $imagesRestored = 0;
        $safetyName = null;
        try {
            // Restore point: a full snapshot of the current data, so a wrong file
            // or a failure half-way can be undone from «پشتیبان‌های موجود».
            $safetyName = 'pre_restore_' . date('Y-m-d_H-i-s') . '.sql.gz';
            $snap = $this->createBackupWithPHP($safetyName, 'full');
            if (isset($snap['error'])) {
                throw new Exception('پشتیبان ایمنی پیش از بازیابی ساخته نشد؛ بازیابی انجام نشد: ' . $snap['error']);
            }

            if ($prep['tmpDir'] && $restoreImages) {
                $imagesRestored = $this->restoreProductImagesFromDir($prep['tmpDir']);
            }
            $statements = $this->executeSqlStream($prep['sqlPath']);
        } finally {
            foreach ($prep['tmpFiles'] as $f) {
                @unlink($f);
            }
            if ($prep['tmpDir']) {
                $this->rrmdir($prep['tmpDir']);
            }
        }
        $this->pruneSafetyBackups(5);

        // Restored data may carry an older schema: re-run migrations next request.
        $this->conn->query("DELETE FROM settings WHERE setting_key = 'schema_app_version'");

        $msg = 'بازیابی اطلاعات با موفقیت انجام شد.';
        if ($imagesRestored > 0) {
            $msg .= " {$imagesRestored} فایل تصویر نیز بازیابی شد.";
        }
        $msg .= " نقطه بازگشت پیش از بازیابی: {$safetyName}";
        log_activity($this->conn, 'DATABASE_RESTORED', "دیتابیس از فایل {$originalFilename} بازیابی شد ({$statements} دستور)" . ($imagesRestored ? " + {$imagesRestored} تصویر" : '') . ". نقطه بازگشت: {$safetyName}");
        send_json(['success' => true, 'message' => $msg . ' برنامه مجدداً بارگذاری می‌شود.', 'images_restored' => $imagesRestored, 'safety_backup' => $safetyName]);
    }

    /** Keep only the newest $keep pre-restore snapshots. */
    private function pruneSafetyBackups($keep) {
        $files = glob($this->backupDir . 'pre_restore_*.sql.gz') ?: [];
        if (count($files) <= $keep) {
            return;
        }
        usort($files, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
        foreach (array_slice($files, $keep) as $f) {
            @unlink($f);
        }
    }

    /** پیش‌نمایش بازیابی: tables/row counts inside a stored backup, nothing restored. */
    public function previewBackup($data) {
        check_permission('manage_settings');
        require_once __DIR__ . '/../core/BackupCrypto.php';
        $filename = $data['filename'] ?? '';
        $filePath = $this->resolveBackupPath($filename);
        if ($filePath === null || !file_exists($filePath)) {
            send_json(['error' => 'فایل مورد نظر یافت نشد.'], 404);
            return;
        }
        @set_time_limit(300);
        try {
            $prep = $this->prepareRestoreSource($filePath, basename($filename), $data['backup_password'] ?? null);
            try {
                $info = $this->inspectSqlStream($prep['sqlPath']);
                $images = 0;
                if ($prep['tmpDir'] && is_dir($prep['tmpDir'] . 'uploads/products')) {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prep['tmpDir'] . 'uploads/products', FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) { if ($f->isFile()) $images++; }
                }
            } finally {
                foreach ($prep['tmpFiles'] as $f) @unlink($f);
                if ($prep['tmpDir']) $this->rrmdir($prep['tmpDir']);
            }
            $current = [];
            foreach (array_keys($info['tables']) as $t) {
                $safe = $this->conn->real_escape_string($t);
                $exists = $this->conn->query("SHOW TABLES LIKE '{$safe}'");
                if ($exists && $exists->num_rows > 0) {
                    $r = $this->conn->query("SELECT COUNT(*) AS c FROM `{$t}`");
                    $current[$t] = $r ? (int)$r->fetch_assoc()['c'] : null;
                } else {
                    $current[$t] = null;
                }
            }
            send_json(['success' => true, 'header' => $info['header'], 'tables' => $info['tables'], 'current' => $current, 'images' => $images,
                'is_entity_backup' => strpos(basename($filename), 'entity_') === 0]);
        } catch (Exception $e) {
            send_json(['error' => $e->getMessage()], 400);
        }
    }

    public function uploadAndRestore() {
        check_permission('manage_settings');
        try {
            if (!isset($_FILES['backupFile']) || $_FILES['backupFile']['error'] !== UPLOAD_ERR_OK) {
                $uploadError = $_FILES['backupFile']['error'] ?? null;
                // When post_max_size is exceeded, PHP empties $_FILES entirely.
                $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
                $postMaxBytes = $this->iniSizeToBytes((string)ini_get('post_max_size'));
                if (!isset($_FILES['backupFile']) && $contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
                    throw new Exception(
                        'حجم فایل از محدودیت آپلود سرور (' . ini_get('post_max_size') . ') بیشتر است. فایل را کوچک‌تر کنید یا از بازیابی فایل موجود روی سرور استفاده کنید.'
                    );
                }
                if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                    throw new Exception(
                        'حجم فایل از محدودیت آپلود PHP (' . ini_get('upload_max_filesize') . ') بیشتر است. از دکمه بازیابی روی همان فایل در «پشتیبان‌های موجود» استفاده کنید.'
                    );
                }
                if ($uploadError === UPLOAD_ERR_PARTIAL) {
                    throw new Exception('آپلود فایل ناقص ماند. لطفاً دوباره تلاش کنید.');
                }
                if ($uploadError === UPLOAD_ERR_NO_FILE || !isset($_FILES['backupFile'])) {
                    throw new Exception('فایلی انتخاب نشده یا آپلود به سرور نرسیده است.');
                }
                throw new Exception('خطا در آپلود فایل (کد ' . (string)$uploadError . ').');
            }
            $file = $_FILES['backupFile'];
            // Backup restores can include product images; allow up to 1GB.
            $max_bytes = (defined('MAX_BACKUP_UPLOAD_SIZE_MB') ? MAX_BACKUP_UPLOAD_SIZE_MB : 1024) * 1024 * 1024;
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file['size'] > $max_bytes) {
                throw new Exception('حجم فایل آپلود شده بیش از حد مجاز است (حداکثر ۱ گیگابایت).');
            }

            $allowed = array_unique(array_merge(defined('ALLOWED_BACKUP_EXTENSIONS') ? ALLOWED_BACKUP_EXTENSIONS : ['sql','gz','zip'], ['enc']));
            if (!in_array($ext, $allowed, true)) {
                throw new Exception('پسوند فایل پشتیبان نامعتبر است. فقط sql، gz، zip یا enc مجاز است.');
            }

            $restoreImages = !isset($_POST['restore_images']) || $_POST['restore_images'] === '1' || $_POST['restore_images'] === 'true';
            $this->performRestore($file['tmp_name'], $file['name'], $restoreImages, $_POST['backup_password'] ?? null);
        } catch (Exception $e) {
            send_json(['error' => $e->getMessage()], 500);
        }
    }
    
    public function restoreFromServer($data) {
        check_permission('manage_settings');
        $filename = $data['filename'] ?? '';
        $filePath = $this->resolveBackupPath($filename);
        if ($filePath === null) {
            send_json(['error' => 'نام فایل نامعتبر است.'], 400); return;
        }
        if (!file_exists($filePath)) {
            send_json(['error' => 'فایل مورد نظر یافت نشد.'], 404); return;
        }
        $restoreImages = !isset($data['restore_images']) || $data['restore_images'] === true || $data['restore_images'] === 1 || $data['restore_images'] === '1';
        try {
            $this->performRestore($filePath, basename($filename), $restoreImages, $data['backup_password'] ?? null);
        } catch (Exception $e) {
            send_json(['error' => $e->getMessage()], 500);
        }
    }

    public function downloadBackup() {
        check_permission('manage_settings');
        $filename = $_GET['filename'] ?? '';
        $filePath = $this->resolveBackupPath($filename);
        if ($filePath === null) {
            http_response_code(400); echo "Bad Request: Invalid filename."; exit;
        }
        if (file_exists($filePath)) {
            header('Content-Description: File Transfer');
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $ctype = ($ext === 'gz') ? 'application/gzip' : (($ext === 'zip') ? 'application/zip' : 'application/sql');
            header('Content-Type: ' . $ctype);
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            flush(); 
            readfile($filePath);
            exit;
        } else {
            http_response_code(404); echo "Error: File not found."; exit;
        }
    }

    public function checkAutoBackupStatus($data = null) {
        if (empty($_SESSION['user_id'])) {
            send_json(['error' => 'نشست نامعتبر است.'], 401);
            return;
        }
        $settings = [];
        $res = $this->conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('auto_backup_enabled','last_auto_backup_timestamp','auto_backup_include_images')");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        $enabled = ($settings['auto_backup_enabled'] ?? '0') === '1';
        $last = intval($settings['last_auto_backup_timestamp'] ?? 0);
        $due = $enabled && ($last < (time() - 24 * 60 * 60));
        send_json([
            'enabled' => $enabled,
            'due' => $due,
            'last_timestamp' => $last,
            'include_images' => ($settings['auto_backup_include_images'] ?? '0') === '1',
        ]);
    }

    public function runPendingAutoBackup($data = null) {
        if (empty($_SESSION['user_id'])) {
            send_json(['error' => 'نشست نامعتبر است.'], 401);
            return;
        }
        $settings = [];
        $res = $this->conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('auto_backup_enabled','last_auto_backup_timestamp','auto_backup_include_images')");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        if (($settings['auto_backup_enabled'] ?? '0') !== '1') {
            send_json(['success' => true, 'skipped' => true, 'message' => 'پشتیبان‌گیری خودکار غیرفعال است.']);
            return;
        }
        $last = intval($settings['last_auto_backup_timestamp'] ?? 0);
        if ($last >= (time() - 24 * 60 * 60)) {
            send_json(['success' => true, 'skipped' => true, 'message' => 'پشتیبان خودکار امروز قبلاً گرفته شده است.']);
            return;
        }

        $lockPath = $this->backupDir . 'auto_backup_run.lock';
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if ($lockHandle) {
                fclose($lockHandle);
            }
            send_json(['success' => true, 'skipped' => true, 'message' => 'پشتیبان خودکار در حال انجام است.']);
            return;
        }

        try {
            // Re-read after acquiring lock (another request may have finished)
            $settings = [];
            $res = $this->conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('auto_backup_enabled','last_auto_backup_timestamp','auto_backup_include_images')");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            }
            if (($settings['auto_backup_enabled'] ?? '0') !== '1') {
                send_json(['success' => true, 'skipped' => true, 'message' => 'پشتیبان‌گیری خودکار غیرفعال است.']);
                return;
            }
            $last = intval($settings['last_auto_backup_timestamp'] ?? 0);
            if ($last >= (time() - 24 * 60 * 60)) {
                send_json(['success' => true, 'skipped' => true, 'message' => 'پشتیبان خودکار امروز قبلاً گرفته شده است.']);
                return;
            }

            // Claim before long-running backup so overlapping callers see "already done"
            $now = (string)time();
            $claim = $this->conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('last_auto_backup_timestamp', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $claim->bind_param('s', $now);
            $claim->execute();
            $claim->close();

            @set_time_limit(300);
            $includeImages = ($settings['auto_backup_include_images'] ?? '0') === '1';
            $result = $this->runAutomaticBackupInternal($includeImages);

            if (!empty($result['success'])) {
                $payload = [
                    'success' => true,
                    'filename' => $result['filename'] ?? null,
                    'include_images' => $includeImages,
                    'image_files' => $result['image_files'] ?? 0,
                    'message' => 'پشتیبان‌گیری خودکار با موفقیت انجام شد.',
                ];
                if (!empty($result['remote_backup'])) {
                    $payload['remote_backup'] = $result['remote_backup'];
                    if (!empty($result['remote_backup_error'])) {
                        $payload['remote_backup_error'] = $result['remote_backup_error'];
                    }
                }
                if (!empty($result['email_backup'])) {
                    $payload['email_backup'] = $result['email_backup'];
                    if (!empty($result['email_backup_message'])) {
                        $payload['email_backup_message'] = $result['email_backup_message'];
                    }
                    if (!empty($result['email_backup_error'])) {
                        $payload['email_backup_error'] = $result['email_backup_error'];
                    }
                }
                send_json($payload);
            } else {
                // Allow retry later if backup itself failed
                $zero = '0';
                $reset = $this->conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('last_auto_backup_timestamp', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $reset->bind_param('s', $zero);
                $reset->execute();
                $reset->close();
                send_json(['error' => $result['error'] ?? 'پشتیبان‌گیری خودکار ناموفق بود.'], 500);
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function runAutomaticBackup() {
        $secret = $_GET['secret'] ?? '';
        $db_secret = null;
        $res = $this->conn->query("SELECT setting_value FROM settings WHERE setting_key = 'auto_backup_secret' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $db_secret = $res->fetch_assoc()['setting_value'];
        }
        $valid = false;
        if (!empty($secret)) {
            if (defined('CRON_SECRET_KEY') && $secret === CRON_SECRET_KEY) { $valid = true; }
            if (!$valid && $db_secret && hash_equals($db_secret, $secret)) { $valid = true; }
        }
        if (!$valid) {
            http_response_code(403);
            die('Forbidden');
        }
        $includeImages = false;
        $imgRes = $this->conn->query("SELECT setting_value FROM settings WHERE setting_key = 'auto_backup_include_images' LIMIT 1");
        if ($imgRes && $imgRes->num_rows > 0) {
            $includeImages = ($imgRes->fetch_assoc()['setting_value'] === '1');
        }
        $result = $this->runAutomaticBackupInternal($includeImages);
        if (empty($result['success'])) {
            http_response_code(500);
            echo 'FAILED: ' . ($result['error'] ?? 'unknown');
            exit;
        }
        $now = (string)time();
        $stmt = $this->conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('last_auto_backup_timestamp', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param('s', $now);
        $stmt->execute();
        $stmt->close();
        echo 'OK';
        exit;
    }
    
    public function runAutomaticBackupInternal($includeImages = false) {
        $filename = "auto_full_" . date('Y-m-d') . '.sql.gz';
        // Avoid colliding with today's existing file
        if (file_exists($this->backupDir . $filename) || file_exists($this->backupDir . 'auto_full_' . date('Y-m-d') . '.with_images.zip')) {
            $filename = "auto_full_" . date('Y-m-d_H-i-s') . '.sql.gz';
        }
        $result = $this->createBackupWithPHP($filename, 'full');
        
        if (isset($result['success'])) {
            $finalFilename = $filename;
            $imageFiles = 0;
            if ($includeImages) {
                $wrapped = $this->wrapBackupWithImages($filename);
                if (isset($wrapped['error'])) {
                    log_activity($this->conn, 'AUTO_BACKUP_FAILED', $wrapped['error']);
                    return ['error' => $wrapped['error']];
                }
                $finalFilename = $wrapped['filename'];
                $imageFiles = intval($wrapped['image_files'] ?? 0);
            }

            $filePath = $this->backupDir . $finalFilename;
            list($offPath, $offName, $offTemp) = $this->offsiteCopy($filePath, $finalFilename);
            $remoteResult = RemoteBackup::uploadBackupFile($this->conn, $offPath, $offName);

            $this->enforceRetentionPolicy();
            log_activity($this->conn, 'AUTO_BACKUP_SUCCESS', "پشتیبان خودکار {$finalFilename} ایجاد شد" . ($includeImages ? ' (با تصاویر)' : '') . ".");
            $out = ['success' => true, 'filename' => $finalFilename, 'image_files' => $imageFiles];
            if (!empty($remoteResult['success'])) {
                log_activity($this->conn, 'REMOTE_BACKUP_SENT', "پشتیبان خودکار {$finalFilename} به سرور دیگر ارسال شد.");
                $out['remote_backup'] = 'success';
            } elseif (!empty($remoteResult['error'])) {
                log_activity($this->conn, 'REMOTE_BACKUP_FAILED', "ارسال پشتیبان خودکار {$finalFilename} به سرور دیگر ناموفق بود: {$remoteResult['error']}");
                $out['remote_backup'] = 'failed';
                $out['remote_backup_error'] = $remoteResult['error'];
            }

            $this->appendEmailBackupResult($out, $offPath, $offName, 'auto');
            if ($offTemp) {
                @unlink($offPath);
            }
            return $out;
        }

        log_activity($this->conn, 'AUTO_BACKUP_FAILED', $result['error']);
        return ['error' => $result['error'] ?? 'unknown'];
    }

    public function testRemoteBackup($data = null) {
        check_permission('manage_settings');
        $result = RemoteBackup::testConnection($this->conn);
        if (!empty($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error'] ?? 'تست اتصال ناموفق بود.'], 400);
        }
    }

    public function receiveRemoteBackup($data = null) {
        RemoteBackup::receiveBackup($this->conn, $this->backupDir);
    }
    
    private function enforceRetentionPolicy() {
        $settings_res = $this->conn->query("SELECT setting_value FROM settings WHERE setting_key = 'auto_backup_retention_days'");
        $retention_days = ($settings_res && $settings_res->num_rows > 0) ? intval($settings_res->fetch_assoc()['setting_value']) : 7;
        if ($retention_days < 1) {
            // 0/empty would make the cutoff "now" and delete the backup just taken.
            $retention_days = 7;
        }

        $files = array_merge(
            glob($this->backupDir . 'auto_full_*.sql.gz') ?: [],
            glob($this->backupDir . 'auto_full_*.with_images.zip') ?: []
        );
        if (!$files) return;
        
        $cutoff_time = time() - ($retention_days * 86400);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                unlink($file);
            }
        }
    }
}
