<?php
/**
 * Shared image processing helpers for temp rembg / flatten.
 * Used by ProductController::processImage
 */

require_once __DIR__ . '/RembgHelper.php';

class ImageProcessHelper {
    public static function ensureTmpDir() {
        $dir = __DIR__ . '/../../uploads/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        // cleanup files older than 24h
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        return $dir;
    }

    public static function flattenPngToJpeg($pngPathOrBinary, $destJpeg, $bgHex = '#ffffff', $quality = 90) {
        $isPath = is_string($pngPathOrBinary) && is_file($pngPathOrBinary);
        $src = $isPath
            ? @imagecreatefrompng($pngPathOrBinary)
            : @imagecreatefromstring($pngPathOrBinary);
        if (!$src) return false;

        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        $rgb = self::hexToRgb($bgHex);
        $fill = imagecolorallocate($dst, $rgb['r'], $rgb['g'], $rgb['b']);
        imagefill($dst, 0, 0, $fill);
        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);
        $ok = imagejpeg($dst, $destJpeg, $quality);
        imagedestroy($dst);
        return (bool) $ok;
    }

    public static function hexToRgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return ['r' => 255, 'g' => 255, 'b' => 255];
        }
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2)),
        ];
    }

    public static function saveGdImage($image, $path, $mimePrefer = 'image/png', $bg = 'transparent') {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if ($bg !== 'transparent' && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $bg)) {
            // flatten then jpeg
            $w = imagesx($image);
            $h = imagesy($image);
            $dst = imagecreatetruecolor($w, $h);
            $rgb = self::hexToRgb($bg);
            $fill = imagecolorallocate($dst, $rgb['r'], $rgb['g'], $rgb['b']);
            imagefill($dst, 0, 0, $fill);
            imagealphablending($dst, true);
            imagecopy($dst, $image, 0, 0, 0, 0, $w, $h);
            $path = preg_replace('/\.[^.]+$/', '.jpg', $path);
            $ok = imagejpeg($dst, $path, 90);
            imagedestroy($dst);
            return $ok ? ['path' => $path, 'mime' => 'image/jpeg'] : false;
        }

        imagesavealpha($image, true);
        $ok = imagepng($image, $path, 6);
        return $ok ? ['path' => $path, 'mime' => 'image/png'] : false;
    }
}
