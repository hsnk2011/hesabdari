<?php
// /api/models/UserSettings.php
require_once __DIR__ . '/BaseModel.php';

class UserSettings extends BaseModel {
    protected $tableName = 'user_settings';
    protected $hasEntityId = false;

    public function __construct($db) {
        parent::__construct($db);
        $this->ensureTable();
    }

    private function ensureTable() {
        $sql = "CREATE TABLE IF NOT EXISTS `user_settings` (
            `user_id` INT NOT NULL,
            `setting_key` VARCHAR(64) NOT NULL,
            `setting_value` TEXT NOT NULL,
            PRIMARY KEY (`user_id`, `setting_key`)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $this->conn->query($sql);
    }

    private static $allowedKeys = [
        'print_company_name',
        'print_company_phone',
        'print_company_address',
        'print_logo_url',
        'print_bank_name',
        'print_account_number',
        'print_card_number',
        'print_sheba_number',
        'print_account_holder',
        'print_show_logo',
        'print_show_payment_info',
        'print_show_company_info',
        'print_show_signature_line',
        'print_header_text',
        'print_footer_text',
        'print_terms',
        'print_template'
    ];

    private static $booleanKeys = [
        'print_show_logo',
        'print_show_payment_info',
        'print_show_company_info',
        'print_show_signature_line'
    ];

    public function getUserPrintSettings($userId) {
        $settings = [];
        $stmt = $this->conn->prepare("SELECT setting_key, setting_value FROM `{$this->tableName}` WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        foreach ($result as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public function saveUserPrintSettings($userId, $settings_array) {
        if (empty($settings_array) || !is_array($settings_array)) {
            return false;
        }

        $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

        foreach ($settings_array as $key => $value) {
            if (!in_array($key, self::$allowedKeys)) {
                continue;
            }

            if (in_array($key, self::$booleanKeys)) {
                $value = ($value === '1' || $value === 1 || $value === true) ? '1' : '0';
            }

            if ($key === 'print_template') {
                $value = ($value === 'compact') ? 'compact' : 'classic';
            }

            if ($key === 'print_logo_url' && !empty($value)) {
                if (!preg_match('/^(\/|https?:\/\/)/i', $value)) {
                    continue; // invalid URL
                }
                if (preg_match('/^(javascript|data):/i', $value)) {
                    continue; // block dangerous URLs
                }
            }

            $stmt->bind_param("iss", $userId, $key, $value);
            $stmt->execute();
        }
        $stmt->close();
        return true;
    }
}
