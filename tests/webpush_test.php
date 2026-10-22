<?php
/**
 * Web Push without libraries: RFC 8291 payload encryption (checked against the
 * RFC §5 example) and the VAPID ES256 authorization header.
 * Run: php tests/webpush_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/core/WebPush.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }
$b64d = fn(string $s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));

// RFC 8291 §5 (copied from https://www.rfc-editor.org/rfc/rfc8291.txt)
$plaintext = 'When I grow up, I want to be a watermelon';
$authSecret = 'BTBZMqHH6r4Tts7J_aSIgg';
$uaPublic = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
$asPrivate = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
$salt = 'DGv6ra1nlYgDCS1FRnbzlw';
$expected = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27ml'
    . 'mlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPT'
    . 'pK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

$body = WebPush::encrypt($plaintext, $uaPublic, $authSecret, $asPrivate, $salt);
expect_true($body === $b64d($expected), 'encrypt() reproduces the RFC 8291 §5 body byte for byte');

$random = WebPush::encrypt('hello', $uaPublic, $authSecret);
expect_true(strlen($random) === 16 + 4 + 1 + 65 + strlen('hello') + 1 + 16, 'encrypt() with random salt/key has header + record + tag length');

$keys = WebPush::generateVapidKeys();
$pub = $b64d($keys['public']);
expect_true(strlen($pub) === 65 && $pub[0] === "\x04", 'generated VAPID public key is 65-byte uncompressed point');
expect_true(strpos($keys['private_pem'], 'PRIVATE KEY') !== false, 'generated VAPID private key is PEM');

$header = WebPush::vapidAuthHeader('https://fcm.googleapis.com/fcm/send/abc', 'mailto:admin@example.com', $keys['public'], $keys['private_pem'], time() + 3600);
expect_true((bool)preg_match('/^vapid t=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/', $header, $m), 'header has the form "vapid t=<jwt>, k=<key>"');
[$h64, $c64, $s64] = explode('.', $m[1] ?? '..');
expect_true(json_decode($b64d($h64), true) === ['typ' => 'JWT', 'alg' => 'ES256'], 'JWT header is {"typ":"JWT","alg":"ES256"}');
$claims = json_decode($b64d($c64), true) ?: [];
expect_true(($claims['aud'] ?? '') === 'https://fcm.googleapis.com' && ($claims['sub'] ?? '') === 'mailto:admin@example.com', 'JWT aud is the push origin and sub is the subject');
expect_true(($m[2] ?? '') === $keys['public'], 'k= carries the VAPID public key');
$sig = $b64d($s64);
expect_true(strlen($sig) === 64, 'signature is raw 64-byte r||s');
$der = WebPush::rawSignatureToDer($sig);
$pubPem = WebPush::publicPemFromRaw($pub);
expect_true(openssl_verify($h64 . '.' . $c64, $der, $pubPem, OPENSSL_ALGO_SHA256) === 1, 'signature verifies with the VAPID public key');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
