<?php
// /api/models/Settings.php

class Settings {
    protected $conn;
    protected $tableName = 'settings';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAllSettings() {
        $settings = [];
        $result = $this->conn->query("SELECT * FROM `{$this->tableName}`");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        return $settings;
    }

    public function saveAllSettings($settingsData) {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            
            foreach ($settingsData as $key => $value) {
                // Basic validation/sanitization
                $sanitized_key = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
                $sanitized_value = is_scalar($value) ? (string)$value : json_encode($value);

                if (!empty($sanitized_key)) {
                    $stmt->bind_param("ss", $sanitized_key, $sanitized_value);
                    if (!$stmt->execute()) {
                        throw new Exception("خطا در ذخیره تنظیمات برای کلید: " . $sanitized_key);
                    }
                }
            }
            
            $stmt->close();
            $this->conn->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }
}