<?php
/**
 * Admin API for reading / clearing application debug logs (AppLogger).
 */

require_once __DIR__ . '/../core/AppLogger.php';

class LogController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAppLogs($data) {
        check_permission('manage_settings');

        $result = AppLogger::readEntries([
            'limit' => intval($data['limit'] ?? 100),
            'offset' => intval($data['offset'] ?? 0),
            'level' => $data['level'] ?? '',
            'search' => $data['search'] ?? '',
            'channel' => $data['channel'] ?? '',
        ]);

        send_json($result);
    }

    public function getAppLogStats($data) {
        check_permission('manage_settings');
        send_json(AppLogger::getStats());
    }

    public function clearAppLogs($data) {
        check_permission('manage_settings');

        $ok = AppLogger::clear(0);
        if ($ok && function_exists('log_activity')) {
            log_activity($this->conn, 'CLEAR_APP_LOGS', 'لاگ‌های فنی برنامه پاک شد.');
        }
        AppLogger::info('App logs cleared by admin', ['source' => 'log_controller']);

        send_json(['success' => $ok]);
    }

    public function downloadAppLogs($data) {
        check_permission('manage_settings');

        $file = AppLogger::getLogFile();
        if (!is_file($file) || !is_readable($file)) {
            send_json(['error' => 'فایل لاگ یافت نشد.'], 404);
            return;
        }

        $filename = 'app-log-' . date('Ymd-His') . '.log';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store');
        readfile($file);
        exit;
    }

    /**
     * Accept client-side (browser) errors. Authenticated users only; rate-limited lightly.
     */
    public function reportClientError($data) {
        if (empty($_SESSION['user_id'])) {
            send_json(['error' => 'Unauthorized'], 401);
            return;
        }

        // Simple per-session throttle: max 30 client reports / 5 minutes
        $now = time();
        if (!isset($_SESSION['_client_log_bucket'])) {
            $_SESSION['_client_log_bucket'] = ['start' => $now, 'count' => 0];
        }
        $bucket = &$_SESSION['_client_log_bucket'];
        if (($now - $bucket['start']) > 300) {
            $bucket = ['start' => $now, 'count' => 0];
        }
        $bucket['count']++;
        if ($bucket['count'] > 30) {
            send_json(['success' => false, 'throttled' => true]);
            return;
        }

        $level = strtoupper(trim($data['level'] ?? 'ERROR'));
        if (!in_array($level, ['WARNING', 'ERROR', 'CRITICAL'], true)) {
            $level = 'ERROR';
        }

        $message = trim((string)($data['message'] ?? 'Client error'));
        if ($message === '') {
            $message = 'Client error';
        }

        AppLogger::setChannel('client');
        AppLogger::log($level, $message, [
            'source' => 'client',
            'url' => isset($data['url']) ? substr((string)$data['url'], 0, 500) : null,
            'stack' => isset($data['stack']) ? substr((string)$data['stack'], 0, 3000) : null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 300) : null,
            'component' => $data['component'] ?? null,
            'extra' => is_array($data['extra'] ?? null) ? $data['extra'] : null,
        ]);
        AppLogger::setChannel('app');

        send_json(['success' => true, 'request_id' => AppLogger::getRequestId()]);
    }
}
