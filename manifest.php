<?php
/**
 * Dynamic PWA manifest — name/labels come from the settings table so the same
 * codebase can serve any brand. The relative "id"/"start_url" keep the
 * installed-app identity stable no matter which sub-directory we are mounted at.
 */
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/core/helpers.php';

$appTitle = 'سیستم حسابداری';
$companyName = '';
$themeColor = '#4f46e5';
$backgroundColor = '#f8f9fa';

try {
    $conn = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn && !$conn->connect_error) {
        $conn->set_charset('utf8mb4');
        $appTitle = trim((string)get_app_setting($conn, 'app_title', '')) !== ''
            ? trim((string)get_app_setting($conn, 'app_title', ''))
            : $appTitle;
        $companyName = trim((string)get_app_setting($conn, 'print_company_name', ''));
        $tc = trim((string)get_app_setting($conn, 'pwa_theme_color', ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $tc)) {
            $themeColor = $tc;
        }
        $conn->close();
    }
} catch (Throwable $e) { /* fall back to defaults */ }

$brand = $companyName !== '' ? $companyName : $appTitle;

$manifest = [
    'name' => $appTitle . ($companyName !== '' ? " — {$companyName}" : ''),
    'short_name' => $brand,
    'description' => "سیستم جامع مدیریت حسابداری، فروش، انبار و گزارش‌گیری {$brand}",
    'lang' => 'fa',
    'dir' => 'rtl',
    'start_url' => './index.php',
    'scope' => './',
    // Relative id: resolves to the mount path (e.g. /hesabdari/), same as the
    // previous hardcoded value, but works for any deployment path.
    'id' => './',
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui', 'browser'],
    'orientation' => 'portrait',
    'theme_color' => $themeColor,
    'background_color' => $backgroundColor,
    'categories' => ['business', 'finance', 'productivity'],
    'icons' => [
        ['src' => 'assets/icons/icon-72.png', 'sizes' => '72x72', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-96.png', 'sizes' => '96x96', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-128.png', 'sizes' => '128x128', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-144.png', 'sizes' => '144x144', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-152.png', 'sizes' => '152x152', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-384.png', 'sizes' => '384x384', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => 'assets/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'screenshots' => [
        [
            'src' => 'assets/screenshots/screenshot-narrow.png',
            'sizes' => '720x1280',
            'type' => 'image/png',
            'form_factor' => 'narrow',
            'label' => 'نمای موبایل ' . $appTitle,
        ],
        [
            'src' => 'assets/screenshots/screenshot-wide.png',
            'sizes' => '1280x720',
            'type' => 'image/png',
            'form_factor' => 'wide',
            'label' => 'نمای دسکتاپ ' . $appTitle,
        ],
    ],
    'shortcuts' => [
        [
            'name' => 'داشبورد',
            'short_name' => 'داشبورد',
            'description' => 'مشاهده داشبورد و خلاصه وضعیت',
            'url' => './index.php#dashboard',
            'icons' => [['src' => 'assets/icons/icon-96.png', 'sizes' => '96x96']],
        ],
        [
            'name' => 'فاکتور فروش جدید',
            'short_name' => 'فروش',
            'description' => 'ثبت فاکتور فروش',
            'url' => './index.php#sales',
            'icons' => [['src' => 'assets/icons/icon-96.png', 'sizes' => '96x96']],
        ],
        [
            'name' => 'آنالیز فروش',
            'short_name' => 'آنالیز',
            'description' => 'گزارش‌های تحلیلی فروش',
            'url' => './analytics/index.php',
            'icons' => [['src' => 'assets/icons/icon-96.png', 'sizes' => '96x96']],
        ],
    ],
];

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=300');
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
