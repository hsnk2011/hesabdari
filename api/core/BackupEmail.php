<?php
// /api/core/BackupEmail.php

require_once __DIR__ . '/MailService.php';

class BackupEmail {
    /**
     * @param string $context manual|auto
     */
    public static function sendBackupFile($conn, $filePath, $filename, $context = 'manual') {
        $settings = MailService::getSettings($conn);
        if (!MailService::isConfigured($settings)) {
            return ['skipped' => true, 'reason' => 'smtp_not_configured'];
        }

        $recipients = MailService::parseRecipients($settings['backup_email_to'] ?? '');
        if (empty($recipients)) {
            return ['error' => 'ایمیل دریافت بکاپ تنظیم نشده است.'];
        }

        if (!is_file($filePath)) {
            return ['error' => 'فایل پشتیبان برای ارسال یافت نشد.'];
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(600);

        $storedFilename = $filename;
        $tmpExtract = null;
        $imagesNote = '';

        try {
            if (preg_match('/\.with_images\.zip$/i', $filename)) {
                $extracted = self::extractDatabaseFromImagesZip($filePath, $filename);
                if (!empty($extracted['error'])) {
                    return ['error' => $extracted['error']];
                }
                $filePath = $extracted['path'];
                $filename = $extracted['filename'];
                $tmpExtract = $extracted['path'];
                $imagesNote = '<p><strong>توجه:</strong> فایل کامل با تصاویر روی سرور ذخیره شده است (<code>'
                    . htmlspecialchars($storedFilename, ENT_QUOTES, 'UTF-8') . '</code>). '
                    . 'در این ایمیل فقط دیتابیس (بدون تصاویر) پیوست شده است.</p>';
            }

            $size = filesize($filePath);
            $site = trim((string)($settings['mail_from_name'] ?? 'سیستم حسابداری'));
            $typeLabel = ($context === 'auto') ? 'خودکار' : 'دستی';
            $sizeFmt = self::formatBytes($size);
            $subject = "پشتیبان {$typeLabel} — {$site} — {$filename}";

            $attachments = [[
                'path' => $filePath,
                'filename' => $filename,
                'mime' => 'application/gzip',
            ]];

            $html = '<div dir="rtl" style="font-family:Tahoma,sans-serif">';
            $html .= '<p>یک فایل پشتیبان <strong>' . htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') . '</strong> ایجاد شد.</p>';
            $html .= '<ul>';
            $html .= '<li><strong>نام فایل پیوست:</strong> ' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '</li>';
            if ($storedFilename !== $filename) {
                $html .= '<li><strong>فایل روی سرور:</strong> ' . htmlspecialchars($storedFilename, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $html .= '<li><strong>حجم پیوست:</strong> ' . htmlspecialchars($sizeFmt, ENT_QUOTES, 'UTF-8') . '</li>';
            $html .= '<li><strong>زمان:</strong> ' . date('Y-m-d H:i:s') . '</li>';
            $html .= '</ul>';
            if ($imagesNote !== '') {
                $html .= $imagesNote;
            }
            $html .= '<p>فایل پشتیبان دیتابیس به این ایمیل پیوست شده است.</p>';
            $html .= '</div>';

            $result = MailService::send($conn, $recipients, $subject, $html, '', $attachments);
            if (!empty($result['success'])) {
                return [
                    'success' => true,
                    'attached' => true,
                    'sent_to' => $result['sent_to'] ?? $recipients,
                    'email_filename' => $filename,
                    'stored_filename' => $storedFilename,
                    'message' => ($storedFilename !== $filename)
                        ? 'دیتابیس (بدون تصاویر) به ایمیل ارسال شد.'
                        : 'فایل پشتیبان به ایمیل ارسال شد.',
                ];
            }
            return $result;
        } finally {
            if ($tmpExtract && is_file($tmpExtract)) {
                @unlink($tmpExtract);
            }
        }
    }

  /**
   * Extract database.sql.gz from a .with_images.zip backup for email attachment.
   */
    private static function extractDatabaseFromImagesZip($zipPath, $originalFilename) {
        if (!class_exists('ZipArchive')) {
            return ['error' => 'افزونه ZipArchive برای استخراج دیتابیس از ZIP فعال نیست.'];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['error' => 'باز کردن فایل ZIP پشتیبان برای ایمیل ناموفق بود.'];
        }
        $sqlBinary = $zip->getFromName('database.sql.gz');
        $zip->close();
        if ($sqlBinary === false || $sqlBinary === '') {
            return ['error' => 'دیتابیس در فایل ZIP پشتیبان یافت نشد.'];
        }
        $emailName = preg_replace('/\.with_images\.zip$/i', '.sql.gz', $originalFilename);
        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hesabdari_email_' . uniqid('', true) . '.sql.gz';
        if (file_put_contents($tmpPath, $sqlBinary) === false) {
            return ['error' => 'ذخیره موقت دیتابیس برای ارسال ایمیل ناموفق بود.'];
        }
        return ['path' => $tmpPath, 'filename' => $emailName];
    }

    private static function formatBytes($bytes) {
        $bytes = floatval($bytes);
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }
        return round($bytes / 1024, 2) . ' KB';
    }
}
