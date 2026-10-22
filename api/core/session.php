<?php
// /api/core/session.php — one place for the session cookie flags, so every
// entry point (app, API, scan, analytics, SSO) issues the same hardened cookie.

if (!function_exists('app_session_start')) {
    function app_session_start() {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}
