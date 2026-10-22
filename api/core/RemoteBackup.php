<?php
// /api/core/RemoteBackup.php
// HTTP-based remote backup sync for shared hosting (no SSH required).

class RemoteBackup {
    private static $settingKeys = [
        'remote_backup_enabled',
        'remote_backup_url',
        'remote_backup_secret',
        'remote_backup_receive_secret',
        'remote_backup_retention_days',
    ];

    public static function getSettings($conn) {
        $settings = [];
        $keys = implode("','", self::$settingKeys);
        $res = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('{$keys}')");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        return $settings;
    }

    public static function isUploadEnabled($settings) {
        return ($settings['remote_backup_enabled'] ?? '0') === '1'
            && !empty(trim($settings['remote_backup_url'] ?? ''))
            && !empty(trim($settings['remote_backup_secret'] ?? ''));
    }

    public static function uploadBackupFile($conn, $filePath, $filename) {
        if (!function_exists('curl_init')) {
            return ['error' => 'افزونه cURL در سرور فعال نیست.'];
        }
        if (!file_exists($filePath)) {
            return ['error' => 'فایل پشتیبان برای ارسال یافت نشد.'];
        }

        $settings = self::getSettings($conn);
        if (!self::isUploadEnabled($settings)) {
            return ['skipped' => true];
        }

        $url = trim($settings['remote_backup_url']);
        $secret = trim($settings['remote_backup_secret']);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['error' => 'آدرس سرور پشتیبان از راه دور نامعتبر است.'];
        }

        $mime = preg_match('/\.zip$/i', $filename) ? 'application/zip' : 'application/gzip';
        $cfile = new CURLFile($filePath, $mime, $filename);
        $post = [
            'secret' => $secret,
            'filename' => $filename,
            'backupFile' => $cfile,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['error' => 'خطا در ارتباط با سرور مقصد: ' . $curlError];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['success'])) {
            return ['success' => true, 'remote_filename' => $decoded['filename'] ?? null];
        }

        $message = $decoded['error'] ?? "پاسخ نامعتبر از سرور مقصد (کد {$httpCode}).";
        return ['error' => $message];
    }

    public static function testConnection($conn) {
        if (!function_exists('curl_init')) {
            return ['error' => 'افزونه cURL در سرور فعال نیست.'];
        }

        $settings = self::getSettings($conn);
        if (!self::isUploadEnabled($settings)) {
            return ['error' => 'ابتدا ارسال پشتیبان به سرور دیگر را فعال و تنظیمات را تکمیل کنید.'];
        }

        $url = trim($settings['remote_backup_url']);
        $secret = trim($settings['remote_backup_secret']);

        $separator = (strpos($url, '?') !== false) ? '&' : '?';
        $testUrl = $url . $separator . 'test=1';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $testUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['secret' => $secret, 'test' => '1'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['error' => 'خطا در ارتباط با سرور مقصد: ' . $curlError];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['success'])) {
            return ['success' => true, 'message' => $decoded['message'] ?? 'اتصال با سرور مقصد برقرار شد.'];
        }

        return ['error' => $decoded['error'] ?? "تست اتصال ناموفق بود (کد {$httpCode})."];
    }

    public static function receiveBackup($conn, $backupDir) {
        $settings = self::getSettings($conn);
        $expectedSecret = trim($settings['remote_backup_receive_secret'] ?? '');

        if ($expectedSecret === '') {
            send_json(['error' => 'دریافت پشتیبان از راه دور روی این سرور فعال نیست.'], 403);
            return;
        }

        $providedSecret = $_POST['secret'] ?? $_GET['secret'] ?? '';
        if (!hash_equals($expectedSecret, $providedSecret)) {
            send_json(['error' => 'کلید امنیتی نامعتبر است.'], 403);
            return;
        }

        if (!empty($_GET['test']) || (!empty($_POST['test']) && $_POST['test'] === '1')) {
            send_json([
                'success' => true,
                'message' => 'اتصال برقرار است. این سرور آماده دریافت پشتیبان است.',
            ]);
            return;
        }

        if (!isset($_FILES['backupFile']) || $_FILES['backupFile']['error'] !== UPLOAD_ERR_OK) {
            send_json(['error' => 'فایل پشتیبان دریافت نشد.'], 400);
            return;
        }

        $originalName = $_POST['filename'] ?? $_FILES['backupFile']['name'];
        $originalName = basename($originalName);
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.(sql(\.gz)?|zip|enc)$/i', $originalName)) {
            send_json(['error' => 'نام فایل پشتیبان نامعتبر است.'], 400);
            return;
        }

        $max_bytes = (defined('MAX_BACKUP_UPLOAD_SIZE_MB') ? MAX_BACKUP_UPLOAD_SIZE_MB : 1024) * 1024 * 1024;
        if ($_FILES['backupFile']['size'] > $max_bytes) {
            send_json(['error' => 'حجم فایل پشتیبان بیش از حد مجاز است (حداکثر ۱ گیگابایت).'], 413);
            return;
        }

        $remoteDir = rtrim($backupDir, '/\\') . '/remote/';
        if (!is_dir($remoteDir)) {
            mkdir($remoteDir, 0755, true);
        }

        $safeName = 'remote_' . preg_replace('/^remote_/', '', $originalName);
        $destPath = $remoteDir . $safeName;

        if (!move_uploaded_file($_FILES['backupFile']['tmp_name'], $destPath)) {
            send_json(['error' => 'خطا در ذخیره فایل پشتیبان روی سرور.'], 500);
            return;
        }

        self::enforceRemoteRetention($conn, $remoteDir);
        log_activity($conn, 'REMOTE_BACKUP_RECEIVED', "پشتیبان از راه دور با نام {$safeName} دریافت شد.");

        send_json([
            'success' => true,
            'filename' => $safeName,
            'message' => 'پشتیبان با موفقیت دریافت و ذخیره شد.',
        ]);
    }

    public static function enforceRemoteRetention($conn, $remoteDir) {
        $settings = self::getSettings($conn);
        $retention_days = intval($settings['remote_backup_retention_days'] ?? 30);
        if ($retention_days < 1) {
            $retention_days = 30;
        }

        // .zip (with images) and .enc copies are pruned too; they used to pile up forever.
        $files = array_merge(
            glob(rtrim($remoteDir, '/\\') . '/remote_*.sql.gz') ?: [],
            glob(rtrim($remoteDir, '/\\') . '/remote_*.zip') ?: [],
            glob(rtrim($remoteDir, '/\\') . '/remote_*.enc') ?: []
        );
        if (!$files) {
            return;
        }

        $cutoff_time = time() - ($retention_days * 86400);
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                @unlink($file);
            }
        }
    }
}
