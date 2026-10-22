<?php
// /api/models/EntitySettings.php
require_once __DIR__ . '/BaseModel.php';

class EntitySettings extends BaseModel {
    protected $tableName = 'entity_settings';
    protected $hasEntityId = false;

    public function __construct($db) {
        parent::__construct($db);
        $this->ensureTable();
    }

    private function ensureTable() {
        // CREATE TABLE IF NOT EXISTS commits any open transaction; run it once.
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $exists = $this->conn->query("SHOW TABLES LIKE 'entity_settings'");
        if ($exists && $exists->num_rows > 0) {
            return;
        }
        $sql = "CREATE TABLE IF NOT EXISTS `entity_settings` (
            `entity_id` INT NOT NULL,
            `setting_key` VARCHAR(64) NOT NULL,
            `setting_value` TEXT NOT NULL,
            PRIMARY KEY (`entity_id`, `setting_key`)
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

    /** Per-entity operational settings (not print branding). */
    private static $valueKeys = [
        'zarinpal_deposit_account_id',
        'sales_invoice_prefix',
        'purchase_invoice_prefix',
        'sales_invoice_next_number',
        'purchase_invoice_next_number',
    ];

    public static function valueKeys() {
        return self::$valueKeys;
    }

    public function getValue($entityId, $key, $default = null) {
        $stmt = $this->conn->prepare("SELECT setting_value FROM `{$this->tableName}` WHERE entity_id = ? AND setting_key = ? LIMIT 1");
        $entityId = (int)$entityId;
        $stmt->bind_param('is', $entityId, $key);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt)[0] ?? null;
        return ($row && $row['setting_value'] !== '') ? $row['setting_value'] : $default;
    }

    public function getValues($entityId) {
        $all = $this->getEntityPrintSettings($entityId);
        return array_intersect_key($all, array_flip(self::$valueKeys));
    }

    public function setValues($entityId, array $values) {
        $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $entityId = (int)$entityId;
        foreach ($values as $key => $value) {
            if (!in_array($key, self::$valueKeys, true)) {
                continue;
            }
            $v = trim((string)$value);
            $stmt->bind_param('iss', $entityId, $key, $v);
            $stmt->execute();
        }
        $stmt->close();
        return true;
    }

    public function getEntityPrintSettings($entityId) {
        $settings = [];
        $stmt = $this->conn->prepare("SELECT setting_key, setting_value FROM `{$this->tableName}` WHERE entity_id = ?");
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        foreach ($result as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public function saveEntityPrintSettings($entityId, $settings_array) {
        if (empty($settings_array) || !is_array($settings_array)) {
            return false;
        }

        $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

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
                // Block dangerous URLs (javascript:, data:)
                if (preg_match('/^(javascript|data):/i', $value)) {
                    continue;
                }
                // Value is allowed - can be relative path, absolute path, or URL
            }

            $stmt->bind_param("iss", $entityId, $key, $value);
            $stmt->execute();
        }
        $stmt->close();
        return true;
    }
}
