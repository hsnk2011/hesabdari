<?php
// /api/models/Settings.php
require_once __DIR__ . '/BaseModel.php';

class Settings extends BaseModel {
    protected $tableName = 'settings';
    protected $hasEntityId = false;

    public function getAllSettings() {
        $settings = [];
        $stmt = $this->conn->prepare("SELECT setting_key, setting_value FROM `{$this->tableName}`");
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        foreach ($result as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public function getPublicSettings() {
        $public_keys = [
            'app_title',
            'support_url',
            'standard_dimensions',
            'expense_categories',
            'income_categories',
            // Localization / business rules
            'currency_label',
            'currency_rial_divisor',
            'default_unit',
            'dimension_cm_threshold',
            'dimension_area_map',
            'ar_aging_buckets',
            'check_discount_days_per_month',
            // Invoice price history suggestions
            'invoice_price_history_enabled',
            'invoice_price_history_similar',
            'invoice_price_history_person_prefer',
            'invoice_price_history_limit',
            // Print settings
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
        $placeholders = implode(',', array_fill(0, count($public_keys), '?'));
        $types = str_repeat('s', count($public_keys));
        
        $settings = [];
        $stmt = $this->conn->prepare("SELECT setting_key, setting_value FROM `{$this->tableName}` WHERE setting_key IN ($placeholders)");
        $stmt->bind_param($types, ...$public_keys);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        foreach ($result as $row) {
             $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    // Allowed settings keys for validation
    private static $allowedKeys = [
        'app_title',
        'standard_dimensions',
        'expense_categories',
        'income_categories',
        'auto_backup_enabled',
        'auto_backup_retention_days',
        'auto_backup_secret',
        'auto_backup_include_images',
        'remote_backup_enabled',
        'remote_backup_url',
        'remote_backup_secret',
        'remote_backup_receive_secret',
        'remote_backup_retention_days',
        'smtp_enabled',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'mail_from_address',
        'mail_from_name',
        'backup_email_to',
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
        'print_template',
        'sms_enabled',
        'sms_api_key',
        'sms_sender',
        'sms_mode',
        'sms_template_sales',
        'sms_template_ready',
        'sms_verify_template_sales',
        'sms_verify_template_ready',
        'sms_auto_send_sales',
        'sms_check_alert_enabled',
        'sms_check_alert_days',
        'sms_check_alert_partner_ids',
        'sms_check_alert_types',
        'sms_check_alert_statuses',
        'sms_check_alert_template',
        'sms_check_alert_verify_template',
        'sms_check_alert_last_run',
        'default_tax_rate',
        'zarinpal_enabled',
        'zarinpal_merchant_id',
        'zarinpal_sandbox',
        'zarinpal_deposit_account_id',
        'zarinpal_currency_unit',
        // Localization / business rules
        'currency_label',
        'currency_rial_divisor',
        'default_unit',
        'dimension_cm_threshold',
        'dimension_area_map',
        'ar_aging_buckets',
        'check_discount_days_per_month',
        'share_link_ttl_hours',
        'default_cash_account_id',
        'support_url',
        'bank_import_common_fees',
        'bank_import_fee_cap_ratio',
        'pwa_theme_color',
        'invoice_price_history_enabled',
        'invoice_price_history_similar',
        'invoice_price_history_person_prefer',
        'invoice_price_history_limit',
        'workspace_enabled',
        // «قوانین و رفتار سیستم» in general settings
        'negative_stock_policy',
        'credit_limit_policy',
        'cron_mode',
        'reminder_sms_enabled',
        'reminder_installment_days',
        'reminder_overdue_every_days',
        'sms_verify_template_reminder',
    ];

    // Settings with a fixed set of values: anything else falls back to the first (the default).
    private static $choiceKeys = [
        'negative_stock_policy' => ['allow', 'warn', 'block'],
        'credit_limit_policy' => ['warn', 'off', 'block'],
        'cron_mode' => ['request', 'external'],
    ];

    // Whole-number settings: [min, max].
    private static $rangeKeys = [
        'reminder_installment_days' => [0, 30],
        'reminder_overdue_every_days' => [0, 90],
    ];

    // Boolean settings keys that should only accept '0' or '1'
    private static $booleanKeys = [
        'workspace_enabled',
        'reminder_sms_enabled',
        'auto_backup_enabled',
        'auto_backup_include_images',
        'remote_backup_enabled',
        'smtp_enabled',
        'print_show_logo',
        'print_show_payment_info',
        'print_show_company_info',
        'print_show_signature_line',
        'sms_enabled',
        'sms_auto_send_sales',
        'sms_check_alert_enabled',
        'zarinpal_enabled',
        'zarinpal_sandbox',
        'invoice_price_history_enabled',
        'invoice_price_history_similar',
        'invoice_price_history_person_prefer',
    ];

    public function saveAllSettings($settings_array) {
        if (empty($settings_array) || !is_array($settings_array)) {
            return false;
        }
        
        $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        
        foreach ($settings_array as $key => $value) {
            // Only allow whitelisted keys
            if (!in_array($key, self::$allowedKeys)) {
                continue;
            }
            
            // Validate boolean keys
            if (in_array($key, self::$booleanKeys)) {
                $value = ($value === '1' || $value === 1 || $value === true) ? '1' : '0';
            }

            if (isset(self::$choiceKeys[$key])) {
                $choices = self::$choiceKeys[$key];
                $value = in_array((string)$value, $choices, true) ? (string)$value : $choices[0];
            }
            if (isset(self::$rangeKeys[$key])) {
                [$min, $max] = self::$rangeKeys[$key];
                $value = (string)max($min, min($max, (int)$value));
            }

            if ($key === 'print_template') {
                $value = ($value === 'compact') ? 'compact' : 'classic';
            }

            // Validate logo URL if provided
            if ($key === 'print_logo_url' && !empty($value)) {
                // Allow relative paths (starting with /) or valid URLs
                if (!preg_match('/^(\/|https?:\/\/)/i', $value)) {
                    continue; // Skip invalid URLs
                }
                // Block javascript: and data: URLs
                if (preg_match('/^(javascript|data):/i', $value)) {
                    continue;
                }
            }
            
            $stmt->bind_param("ss", $key, $value);
            $stmt->execute();
        }
        $stmt->close();
        return true;
    }
}