<?php
/**
 * Client for local rembg HTTP service (same as Zeema Home).
 * Default: http://127.0.0.1:7001 — never expose publicly.
 */

class RembgHelper {
    /** Sensible defaults, used only when config.php does not define the constants. */
    private const FALLBACK_URL = 'http://127.0.0.1:7001';
    private const FALLBACK_MODEL = 'isnet-general-use';

    public static function isConfigured() {
        return defined('REMBG_URL') && REMBG_URL !== '';
    }

    public static function baseUrl() {
        if (self::isConfigured()) {
            return rtrim(REMBG_URL, '/');
        }
        return self::FALLBACK_URL;
    }

    public static function defaultModel() {
        if (defined('REMBG_MODEL') && REMBG_MODEL) {
            return REMBG_MODEL;
        }
        return self::FALLBACK_MODEL;
    }

    public static function isHealthy($timeoutSec = 5) {
        if (!self::isConfigured() || !function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init(self::baseUrl() . '/api');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_NOBODY => false,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code > 0 && $code < 500;
    }

    /**
     * Remove background; returns PNG binary or false.
     */
    public static function removeBackground($absolutePath, $model = null, $timeoutSec = 120) {
        if (!self::isConfigured() || !function_exists('curl_init') || !is_file($absolutePath)) {
            return false;
        }
        $model = $model ?: self::defaultModel();
        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
        $cfile = new CURLFile($absolutePath, $mime, basename($absolutePath));

        $ch = curl_init(self::baseUrl() . '/api/remove');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file' => $cfile,
                'model' => $model,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300 || strlen($body) < 100) {
            error_log('RembgHelper failed: HTTP ' . $code . ' ' . $err);
            return false;
        }
        return $body;
    }

    /**
     * Flatten PNG (with alpha) onto white and save as JPEG.
     */
    public static function pngToWhiteJpeg($pngBinary, $destination, $quality = 88) {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return false;
        }
        $src = @imagecreatefromstring($pngBinary);
        if (!$src) {
            return false;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($src);
            return false;
        }
        $dst = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $ok = imagejpeg($dst, $destination, $quality);
        imagedestroy($dst);
        return (bool) $ok;
    }
}
