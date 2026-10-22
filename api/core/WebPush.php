<?php
// /api/core/WebPush.php

/**
 * Web Push sender without external libraries (no composer on the servers).
 *
 *  - Payload encryption: RFC 8291 (aes128gcm content coding, RFC 8188), using
 *    openssl ECDH on P-256, hash_hkdf and AES-128-GCM.
 *  - Authorization: VAPID (RFC 8292), an ES256 JWT signed with openssl.
 *
 * Keys are passed around as base64url strings (no padding); the VAPID private
 * key is kept as PEM.
 */
class WebPush {
    /** DER prefix of a SubjectPublicKeyInfo for an uncompressed P-256 point. */
    const SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    /** DER prefix of a SEC1 ECPrivateKey (version 1, 32-byte d) without the public key. */
    const SEC1_P256_PREFIX = '30310201010420';
    const SEC1_P256_CURVE = 'a00a06082a8648ce3d030107';
    const RECORD_SIZE = 4096;

    public static function b64urlEncode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string {
        $s = strtr(trim($s), '-_', '+/');
        return (string)base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** Raw 65-byte uncompressed P-256 point → PEM public key. */
    public static function publicPemFromRaw(string $raw): string {
        $der = hex2bin(self::SPKI_P256_PREFIX) . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Raw 32-byte private scalar → PEM private key (openssl derives the public point). */
    public static function privatePemFromRaw(string $d): string {
        $der = hex2bin(self::SEC1_P256_PREFIX) . $d . hex2bin(self::SEC1_P256_CURVE);
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /** Uncompressed public point (0x04 || x || y) of an openssl EC key. */
    private static function rawPublicOf($key): string {
        $ec = openssl_pkey_get_details($key)['ec'] ?? null;
        if (!$ec) {
            throw new RuntimeException('Invalid EC key');
        }
        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    /**
     * New P-256 private key as PEM. Built from a random scalar instead of
     * openssl_pkey_new(), which needs an openssl.cnf that PHP on Windows hosts
     * often lacks.
     */
    private static function newP256PrivatePem(): string {
        $order = hex2bin('ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551');
        do {
            $d = random_bytes(32);
        } while (strcmp($d, $order) >= 0 || ltrim($d, "\0") === '');
        return self::privatePemFromRaw($d);
    }

    private static function newP256Key() {
        $key = openssl_pkey_get_private(self::newP256PrivatePem());
        if (!$key) {
            throw new RuntimeException('Cannot create EC key: ' . openssl_error_string());
        }
        return $key;
    }

    /** @return array{public: string, private_pem: string} */
    public static function generateVapidKeys(): array {
        $pem = self::newP256PrivatePem();
        return ['public' => self::b64urlEncode(self::rawPublicOf(openssl_pkey_get_private($pem))), 'private_pem' => $pem];
    }

    /**
     * RFC 8291 encryption of $plaintext for a subscription (p256dh + auth).
     * $asPrivateB64 / $saltB64 are only for tests; normally both are random.
     */
    public static function encrypt(string $plaintext, string $uaPublicB64, string $authB64, ?string $asPrivateB64 = null, ?string $saltB64 = null): string {
        $uaPublic = self::b64urlDecode($uaPublicB64);
        $auth = self::b64urlDecode($authB64);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($auth) !== 16) {
            throw new InvalidArgumentException('Invalid subscription keys');
        }
        $asKey = $asPrivateB64 !== null
            ? openssl_pkey_get_private(self::privatePemFromRaw(self::b64urlDecode($asPrivateB64)))
            : self::newP256Key();
        $asPublic = self::rawPublicOf($asKey);
        $uaKey = openssl_pkey_get_public(self::publicPemFromRaw($uaPublic));
        $ecdh = openssl_pkey_derive($uaKey, $asKey, 32);
        if ($ecdh === false) {
            throw new RuntimeException('ECDH failed: ' . openssl_error_string());
        }
        $salt = $saltB64 !== null ? self::b64urlDecode($saltB64) : random_bytes(16);

        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublic . $asPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Single record: plaintext followed by the 0x02 "last record" delimiter.
        $tag = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('AES-GCM failed');
        }
        return $salt . pack('N', self::RECORD_SIZE) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** "vapid t=<jwt>, k=<public key>" for the push service of $endpoint. */
    public static function vapidAuthHeader(string $endpoint, string $subject, string $publicB64, string $privatePem, int $exp): string {
        $parts = parse_url($endpoint);
        $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $input = self::b64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            . '.' . self::b64urlEncode(json_encode(['aud' => $aud, 'exp' => $exp, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $der = '';
        if (!openssl_sign($input, $der, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed: ' . openssl_error_string());
        }
        return 'vapid t=' . $input . '.' . self::b64urlEncode(self::derSignatureToRaw($der)) . ', k=' . $publicB64;
    }

    /** ECDSA DER signature (SEQUENCE of two INTEGERs) → raw 64-byte r||s. */
    public static function derSignatureToRaw(string $der): string {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    /** Raw 64-byte r||s → ECDSA DER signature. */
    public static function rawSignatureToDer(string $raw): string {
        $ints = '';
        foreach ([substr($raw, 0, 32), substr($raw, 32, 32)] as $int) {
            $int = ltrim($int, "\0");
            if ($int === '' || (ord($int[0]) & 0x80)) {
                $int = "\0" . $int;
            }
            $ints .= "\x02" . chr(strlen($int)) . $int;
        }
        return "\x30" . chr(strlen($ints)) . $ints;
    }

    /**
     * Encrypt and POST one message.
     * @param array $sub     endpoint, p256dh, auth
     * @param array $payload title, body, url (JSON-encoded for sw.js)
     * @param array $vapid   public, private_pem, subject
     * @return array{status: int, gone: bool}
     */
    public static function send(array $sub, array $payload, array $vapid): array {
        $body = self::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sub['p256dh'], $sub['auth']);
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . self::vapidAuthHeader($sub['endpoint'], $vapid['subject'], $vapid['public'], $vapid['private_pem'], time() + 12 * 3600),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: 86400',
                'Urgency: normal',
            ],
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'gone' => in_array($status, [404, 410], true)];
    }
}
