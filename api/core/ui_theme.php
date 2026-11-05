<?php
// /api/core/ui_theme.php
// UI theme ("قالب") resolution shared by index.php and the API.
//   classic            -> the frozen pre-2.6 look (templates/classic)
//   nili/firoozeh/kaghazi/shab -> the redesigned UI (templates)
//   auto               -> nili or shab, following the device's light/dark setting
// A user's own choice lives in user_settings.ui_theme; the installation-wide
// default (set by the admin) in settings.ui_default_theme.

const UI_THEMES = ['nili', 'firoozeh', 'kaghazi', 'shab', 'auto', 'classic'];
const UI_THEME_FALLBACK = 'nili';

function ui_theme_normalize($value, string $fallback = UI_THEME_FALLBACK): string {
    $value = is_string($value) ? trim($value) : '';
    return in_array($value, UI_THEMES, true) ? $value : $fallback;
}

/** 'classic' or 'modern' — which template set a theme renders with. */
function ui_theme_family(string $theme): string {
    return $theme === 'classic' ? 'classic' : 'modern';
}

function ui_default_theme($conn): string {
    if (!$conn) return UI_THEME_FALLBACK;
    try {
        return ui_theme_normalize(get_app_setting($conn, 'ui_default_theme', UI_THEME_FALLBACK));
    } catch (Throwable $e) {
        return UI_THEME_FALLBACK;
    }
}

/** The user's saved theme, or '' when the user never picked one. */
function ui_user_theme($conn, int $userId): string {
    if (!$conn || $userId <= 0) return '';
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = 'ui_theme' LIMIT 1");
        if (!$stmt) return '';
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($value);
        $found = $stmt->fetch() ? (string)$value : '';
        $stmt->close();
        return $found === '' ? '' : ui_theme_normalize($found, '');
    } catch (Throwable $e) {
        return ''; // user_settings may not exist yet on a fresh install
    }
}

/**
 * Theme info for a (possibly logged-out) visitor.
 * @return array{theme:string, user_theme:string, default_theme:string, family:string}
 */
function ui_theme_info($conn, int $userId = 0): array {
    $default = ui_default_theme($conn);
    $user = ui_user_theme($conn, $userId);
    $theme = $user !== '' ? $user : $default;
    return [
        'theme' => $theme,
        'user_theme' => $user,
        'default_theme' => $default,
        'family' => ui_theme_family($theme),
    ];
}

function ui_save_user_theme($conn, int $userId, string $theme): bool {
    if ($userId <= 0 || !in_array($theme, UI_THEMES, true)) return false;
    $stmt = $conn->prepare("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (?, 'ui_theme', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if (!$stmt) return false;
    $stmt->bind_param('is', $userId, $theme);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
