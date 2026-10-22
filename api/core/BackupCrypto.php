<?php
// /api/core/BackupCrypto.php

/**
 * Streaming encryption for backup files sent off the server (remote server,
 * e-mail) or downloaded, using a password from settings.backup_encryption_password.
 *
 * Format (all binary): "HSBENC01" | salt(16) | iv(16) | ciphertext | hmac(32)
 *   key material = PBKDF2-SHA256(password, salt, 100000, 64 bytes)
 *   enc key = first 32 bytes (AES-256-CBC), mac key = last 32 bytes (HMAC-SHA256
 *   over salt|iv|ciphertext). Processed in 1 MB chunks, so large image backups
 *   never have to fit in memory.
 */
class BackupCrypto {
    const MAGIC = 'HSBENC01';
    const CHUNK = 1048576; // multiple of 16
    const ITER = 100000;

    public static function isEncryptedFile($path) {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $head = fread($fh, 8);
        fclose($fh);
        return $head === self::MAGIC;
    }

    private static function keys($password, $salt) {
        $km = hash_pbkdf2('sha256', (string)$password, $salt, self::ITER, 64, true);
        return [substr($km, 0, 32), substr($km, 32, 32)];
    }

    public static function encryptFile($src, $dst, $password) {
        if ((string)$password === '') {
            throw new Exception('رمز رمزنگاری پشتیبان تنظیم نشده است.');
        }
        $in = fopen($src, 'rb');
        $out = fopen($dst, 'wb');
        if (!$in || !$out) {
            throw new Exception('امکان خواندن/نوشتن فایل برای رمزنگاری وجود ندارد.');
        }
        $salt = random_bytes(16);
        $iv = random_bytes(16);
        list($encKey, $macKey) = self::keys($password, $salt);
        $hmac = hash_init('sha256', HASH_HMAC, $macKey);
        fwrite($out, self::MAGIC . $salt . $iv);
        hash_update($hmac, $salt . $iv);

        $chainIv = $iv;
        $buffer = fread($in, self::CHUNK);
        while (true) {
            $next = feof($in) ? '' : fread($in, self::CHUNK);
            $isLast = ($next === '' || $next === false);
            if ($isLast) {
                $ct = openssl_encrypt((string)$buffer, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $chainIv);
            } else {
                $ct = openssl_encrypt($buffer, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $chainIv);
            }
            if ($ct === false) {
                fclose($in); fclose($out); @unlink($dst);
                throw new Exception('رمزنگاری پشتیبان ناموفق بود.');
            }
            fwrite($out, $ct);
            hash_update($hmac, $ct);
            if ($isLast) {
                break;
            }
            $chainIv = substr($ct, -16);
            $buffer = $next;
        }
        fwrite($out, hash_final($hmac, true));
        fclose($in);
        fclose($out);
        return true;
    }

    public static function decryptFile($src, $dst, $password) {
        $size = filesize($src);
        $in = fopen($src, 'rb');
        if (!$in || $size < 8 + 32 + 32 + 16) {
            throw new Exception('فایل رمزنگاری‌شده نامعتبر است.');
        }
        if (fread($in, 8) !== self::MAGIC) {
            fclose($in);
            throw new Exception('این فایل با فرمت رمزنگاری پشتیبان سازگار نیست.');
        }
        $salt = fread($in, 16);
        $iv = fread($in, 16);
        list($encKey, $macKey) = self::keys($password, $salt);
        $ctLen = $size - 8 - 32 - 32;

        // Verify the MAC before writing any plaintext.
        $hmac = hash_init('sha256', HASH_HMAC, $macKey);
        hash_update($hmac, $salt . $iv);
        $remaining = $ctLen;
        while ($remaining > 0) {
            $chunk = fread($in, (int)min(self::CHUNK, $remaining));
            hash_update($hmac, $chunk);
            $remaining -= strlen($chunk);
        }
        $mac = fread($in, 32);
        if (!hash_equals(hash_final($hmac, true), (string)$mac)) {
            fclose($in);
            throw new Exception('رمز پشتیبان اشتباه است یا فایل آسیب دیده است.');
        }

        fseek($in, 8 + 32);
        $out = fopen($dst, 'wb');
        $chainIv = $iv;
        $remaining = $ctLen;
        while ($remaining > 0) {
            $len = (int)min(self::CHUNK, $remaining);
            $chunk = fread($in, $len);
            $remaining -= strlen($chunk);
            $isLast = ($remaining <= 0);
            $pt = openssl_decrypt($chunk, 'aes-256-cbc', $encKey, $isLast ? OPENSSL_RAW_DATA : (OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING), $chainIv);
            if ($pt === false) {
                fclose($in); fclose($out); @unlink($dst);
                throw new Exception('رمزگشایی پشتیبان ناموفق بود.');
            }
            fwrite($out, $pt);
            $chainIv = substr($chunk, -16);
        }
        fclose($in);
        fclose($out);
        return true;
    }
}
