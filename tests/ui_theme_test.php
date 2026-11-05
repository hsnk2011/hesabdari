<?php
/**
 * «قالب» (UI theme): per-user choice, installation default, classic/modern family.
 * Run: php tests/ui_theme_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/core/ui_theme.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Settings.php';
require_once $root . '/api/models/UserSettings.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
new UserSettings($conn); // ensures user_settings exists
$token = 'uit' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$saved = $one("SELECT setting_value FROM settings WHERE setting_key = 'ui_default_theme'");
$userId = 0;

try {
    expect_true(ui_theme_normalize('shab') === 'shab', 'a known theme is kept');
    expect_true(ui_theme_normalize('<b>') === 'nili', 'an unknown theme falls back to nili');
    expect_true(ui_theme_family('classic') === 'classic', 'classic renders the classic templates');
    foreach (['nili', 'firoozeh', 'kaghazi', 'shab', 'auto'] as $t) {
        expect_true(ui_theme_family($t) === 'modern', "$t renders the redesigned templates");
    }

    $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}', 'x')");
    $userId = (int)$conn->insert_id;

    $conn->query("DELETE FROM settings WHERE setting_key = 'ui_default_theme'");
    $info = ui_theme_info($conn, $userId);
    expect_true($info['theme'] === 'nili' && $info['user_theme'] === '', 'with nothing saved the theme is nili');
    expect_true(ui_theme_info($conn, 0)['theme'] === 'nili', 'a logged-out visitor gets the default');

    $settings = new Settings($conn);
    $settings->saveAllSettings(['ui_default_theme' => 'kaghazi']);
    expect_true(($one("SELECT setting_value FROM settings WHERE setting_key = 'ui_default_theme'")['setting_value'] ?? '') === 'kaghazi', 'the admin default is saved');
    $settings->saveAllSettings(['ui_default_theme' => 'evil']);
    expect_true(($one("SELECT setting_value FROM settings WHERE setting_key = 'ui_default_theme'")['setting_value'] ?? '') === 'nili', 'an invalid default is stored as nili');
    $conn->query("UPDATE settings SET setting_value = 'kaghazi' WHERE setting_key = 'ui_default_theme'");

    // get_app_setting caches per request; read the default the way a fresh request would.
    $fresh = fn() => ui_theme_normalize(($one("SELECT setting_value FROM settings WHERE setting_key = 'ui_default_theme'")['setting_value'] ?? ''));
    expect_true($fresh() === 'kaghazi', 'the default reads back');

    expect_true(ui_save_user_theme($conn, $userId, 'classic'), 'a user saves their own theme');
    expect_true(ui_user_theme($conn, $userId) === 'classic', 'the user theme reads back');
    $info = ui_theme_info($conn, $userId);
    expect_true($info['theme'] === 'classic' && $info['family'] === 'classic', "the user's choice wins over the default");
    expect_true(!ui_save_user_theme($conn, $userId, 'nope'), 'an invalid user theme is refused');
    expect_true(ui_user_theme($conn, $userId) === 'classic', 'a refused theme leaves the saved one');
    expect_true(ui_save_user_theme($conn, $userId, 'shab') && ui_theme_info($conn, $userId)['family'] === 'modern', 'switching back to a modern theme');
    expect_true(!ui_save_user_theme($conn, 0, 'nili'), 'no theme is saved without a user');
} finally {
    if ($userId) {
        $conn->query("DELETE FROM user_settings WHERE user_id = {$userId}");
        $conn->query("DELETE FROM users WHERE id = {$userId}");
    }
    $conn->query("DELETE FROM settings WHERE setting_key = 'ui_default_theme'");
    if ($saved) {
        $v = $conn->real_escape_string($saved['setting_value']);
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('ui_default_theme', '{$v}')");
    }
}

echo "\nPassed: $passed, Failed: $failed\n";
exit($failed ? 1 : 0);
