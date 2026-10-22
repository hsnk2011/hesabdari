<?php
/**
 * Kavenegar SMS client (REST)
 * Docs: https://kavenegar.com/rest.html
 *
 * Modes:
 * - simple: sms/send.json (dedicated/shared line + free text)
 * - verify: verify/lookup.json (panel templates %token … — no dedicated line needed)
 */
class KavenegarSms {
    private $apiKey;
    private $sender;
    private $baseUrl = 'https://api.kavenegar.com/v1';

    /** @var array<int,string> */
    public static $statusLabels = [
        1 => 'در صف ارسال',
        2 => 'زمان‌بندی شده',
        4 => 'ارسال‌شده به مخابرات',
        5 => 'ارسال‌شده به مخابرات',
        6 => 'خطا در ارسال به مخابرات',
        10 => 'تحویل شده',
        11 => 'تحویل نشده',
        13 => 'لغو شده',
        14 => 'بلاک شده',
        100 => 'نامشخص',
    ];

    public function __construct($apiKey, $sender = '') {
        $this->apiKey = trim((string)$apiKey);
        $this->sender = trim((string)$sender);
    }

    public function isConfigured() {
        return $this->apiKey !== '';
    }

    public static function statusLabel($code) {
        $code = intval($code);
        return self::$statusLabels[$code] ?? ('وضعیت ' . $code);
    }

    public static function statusCategory($code) {
        $code = intval($code);
        if ($code === 10) return 'delivered';
        if (in_array($code, [11, 6, 13, 14], true)) return 'failed';
        if (in_array($code, [1, 2, 4, 5], true)) return 'pending';
        return 'unknown';
    }

    /**
     * Convert Persian/Arabic digits and normalize Iranian mobile numbers.
     * Returns comma-separated 09xxxxxxxxx values, or '' if none valid.
     */
    public static function normalizeReceptor($receptor) {
        $parts = preg_split('/\s*[,\/،|]\s*/u', (string)$receptor);
        $clean = [];
        foreach ($parts as $p) {
            $normalized = self::normalizeOnePhone($p);
            if ($normalized !== '') {
                $clean[] = $normalized;
            }
        }
        return implode(',', array_unique($clean));
    }

    public static function normalizeOnePhone($raw) {
        $p = trim((string)$raw);
        if ($p === '' || $p === '-' || $p === '—') {
            return '';
        }
        $p = strtr($p, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $p = preg_replace('/[^\d+]/', '', $p);
        if ($p === '') {
            return '';
        }
        if (strpos($p, '0098') === 0) {
            $p = '0' . substr($p, 4);
        } elseif (strpos($p, '+98') === 0) {
            $p = '0' . substr($p, 3);
        } elseif (strpos($p, '98') === 0 && strlen($p) >= 12) {
            $p = '0' . substr($p, 2);
        } elseif (preg_match('/^9\d{9}$/', $p)) {
            $p = '0' . $p;
        }
        if (preg_match('/^09\d{9}$/', $p)) {
            return $p;
        }
        return '';
    }

    /**
     * Free-text send (requires a usable line / sender when panel requires it).
     */
    public function send($receptor, $message, $sender = null) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        $rawReceptor = (string)$receptor;
        $receptor = self::normalizeReceptor($receptor);
        if ($receptor === '') {
            return [
                'success' => false,
                'error' => 'شماره گیرنده معتبر نیست. شماره موبایل باید ۱۱ رقم و با 09 شروع شود (مثال: 09123456789). مقدار دریافتی: ' . mb_substr(trim($rawReceptor), 0, 40),
            ];
        }
        $message = trim((string)$message);
        if ($message === '') {
            return ['success' => false, 'error' => 'متن پیامک خالی است.'];
        }

        $params = [
            'receptor' => $receptor,
            'message' => $message,
        ];
        $line = $sender !== null && $sender !== '' ? $sender : $this->sender;
        if ($line !== '') {
            $params['sender'] = $line;
        }

        return $this->request('sms/send.json', $params);
    }

    /**
     * Verification / Lookup send using approved Kavenegar templates.
     * @param array $tokens keys: token, token2, token3, token10, token20
     */
    public function verifyLookup($receptor, $template, array $tokens = []) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        $rawReceptor = (string)$receptor;
        $receptor = self::normalizeReceptor($receptor);
        if ($receptor === '') {
            return [
                'success' => false,
                'error' => 'شماره گیرنده معتبر نیست. شماره موبایل باید ۱۱ رقم و با 09 شروع شود. مقدار دریافتی: ' . mb_substr(trim($rawReceptor), 0, 40),
            ];
        }
        $template = trim((string)$template);
        if ($template === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $template)) {
            return ['success' => false, 'error' => 'نام الگوی اعتبارسنجی کاوه‌نگار نامعتبر است.'];
        }

        $params = [
            'receptor' => $receptor,
            'template' => $template,
            'type' => 'sms',
        ];

        $token = isset($tokens['token']) ? self::sanitizeToken($tokens['token'], false) : '';
        if ($token === '') {
            $token = '-';
        }
        $params['token'] = $token;

        foreach (['token2', 'token3'] as $k) {
            if (!empty($tokens[$k])) {
                $params[$k] = self::sanitizeToken($tokens[$k], false);
            }
        }
        foreach (['token10', 'token20'] as $k) {
            if (!empty($tokens[$k])) {
                $params[$k] = self::sanitizeToken($tokens[$k], true);
            }
        }

        return $this->request('verify/lookup.json', $params);
    }

    /**
     * token/token2/token3: no spaces; token10/token20: limited spaces allowed.
     */
    public static function sanitizeToken($value, $allowLimitedSpaces = false) {
        $v = trim((string)$value);
        $v = strtr($v, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $v = preg_replace('/[\r\n\t_]+/u', '-', $v);
        if ($allowLimitedSpaces) {
            $v = preg_replace('/\s+/u', ' ', $v);
            $parts = explode(' ', $v);
            if (count($parts) > 6) {
                $v = implode('-', $parts);
            }
        } else {
            $v = preg_replace('/\s+/u', '-', $v);
            $v = str_replace(['/', '\\'], '-', $v);
        }
        if (mb_strlen($v) > 100) {
            $v = mb_substr($v, 0, 100);
        }
        return $v;
    }

    public function accountInfo() {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        return $this->request('account/info.json', [], 'GET');
    }

    public function accountConfig() {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        return $this->request('account/config.json', [], 'GET');
    }

    /**
     * Delivery status for one or more message IDs (comma-separated, max 500).
     */
    public function status($messageids) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        $ids = $this->normalizeMessageIds($messageids);
        if ($ids === '') {
            return ['success' => false, 'error' => 'شناسه پیامک معتبر نیست.'];
        }
        return $this->request('sms/status.json', ['messageid' => $ids], 'GET');
    }

    /**
     * Full message details by message ID(s).
     */
    public function select($messageids) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        $ids = $this->normalizeMessageIds($messageids);
        if ($ids === '') {
            return ['success' => false, 'error' => 'شناسه پیامک معتبر نیست.'];
        }
        return $this->request('sms/select.json', ['messageid' => $ids], 'GET');
    }

    /**
     * Outbox messages between unix timestamps.
     */
    public function selectOutbox($startDate, $endDate, $sender = null) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        $params = [
            'startdate' => intval($startDate),
            'enddate' => intval($endDate),
        ];
        $line = $this->resolveSender($sender);
        if ($line !== '') {
            $params['sender'] = $line;
        }
        return $this->request('sms/selectoutbox.json', $params, 'GET');
    }

    /**
     * Latest outbox messages (pagesize max 500).
     * On 407 (access denied with sender filter), retries without sender.
     */
    public function latestOutbox($pageSize = 50, $sender = null) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        $pageSize = max(1, min(500, intval($pageSize)));
        $params = ['pagesize' => $pageSize];
        $line = $this->resolveSender($sender);
        if ($line !== '') {
            $params['sender'] = $line;
        }
        $result = $this->request('sms/latestoutbox.json', $params, 'GET');
        // Some accounts reject sender filter on latestoutbox (407) or incomplete params.
        if (empty($result['success']) && $line !== '') {
            if ($this->isIncompleteParamsError($result) || !empty($result['access_denied']) || intval($result['status'] ?? 0) === 407) {
                $result = $this->request('sms/latestoutbox.json', ['pagesize' => $pageSize], 'GET');
            }
        }
        return $result;
    }

    /**
     * Count outbox for a range. Kavenegar rejects ranges > 1 day (error 417).
     * This helper sums day-by-day windows.
     */
    public function countOutbox($startDate, $endDate, $status = null, $sender = null) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        $start = intval($startDate);
        $end = intval($endDate);
        if ($end <= $start) {
            $end = $start + 1;
        }
        // Never send "future" end relative to now (clock skew → 417)
        $now = time();
        if ($end > $now) {
            $end = $now;
        }
        if ($start >= $end) {
            $start = max(0, $end - 1);
        }

        // Single day (or less): one request
        if (($end - $start) <= 86400) {
            return $this->countOutboxOnce($start, $end, $status, $sender);
        }

        // Multi-day: sum daily windows (Kavenegar max span is 1 day)
        $sum = 0;
        $anyOk = false;
        $lastError = null;
        $cursor = $start;
        while ($cursor < $end) {
            $chunkEnd = min($end, $cursor + 86400);
            $res = $this->countOutboxOnce($cursor, $chunkEnd, $status, $sender);
            if (!empty($res['success'])) {
                $anyOk = true;
                $e = $res['entries'] ?? [];
                if (is_array($e) && isset($e[0]['sumcount'])) {
                    $sum += intval($e[0]['sumcount']);
                } elseif (is_array($e) && isset($e['sumcount'])) {
                    $sum += intval($e['sumcount']);
                }
            } else {
                $lastError = $res;
                // If sender causes issues, retry chunk without sender once
                if ($this->resolveSender($sender) !== '' && (!empty($res['access_denied']) || intval($res['status'] ?? 0) === 407)) {
                    $res2 = $this->countOutboxOnce($cursor, $chunkEnd, $status, '');
                    if (!empty($res2['success'])) {
                        $anyOk = true;
                        $e = $res2['entries'] ?? [];
                        if (is_array($e) && isset($e[0]['sumcount'])) {
                            $sum += intval($e[0]['sumcount']);
                        } elseif (is_array($e) && isset($e['sumcount'])) {
                            $sum += intval($e['sumcount']);
                        }
                        $lastError = null;
                    }
                }
            }
            $cursor = $chunkEnd;
        }

        if (!$anyOk) {
            return $lastError ?: ['success' => false, 'error' => 'شمارش صندوق خروجی ناموفق بود.'];
        }

        return [
            'success' => true,
            'entries' => [['sumcount' => $sum]],
            'messageid' => null,
            'remaincredit' => null,
            'raw' => ['entries' => [['sumcount' => $sum]]],
        ];
    }

    private function countOutboxOnce($start, $end, $status = null, $sender = null) {
        $params = [
            'startdate' => intval($start),
            'enddate' => intval($end),
        ];
        if ($status !== null && $status !== '') {
            $params['status'] = intval($status);
        }
        $line = $this->resolveSender($sender);
        if ($line !== '') {
            $params['sender'] = $line;
        }
        return $this->request('sms/countoutbox.json', $params, 'GET');
    }

    /**
     * Received (inbox) messages for a line number.
     * Kavenegar only accepts isread=0 (unread) or 1 (read).
     * @param int $isRead 0=unread, 1=read, 2=both (merged client-side)
     */
    public function receive($lineNumber = null, $isRead = 0) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        $line = $this->resolveSender($lineNumber);
        if ($line === '') {
            return ['success' => false, 'error' => 'شماره خط دریافت‌کننده (Sender) الزامی است.'];
        }

        $mode = intval($isRead);
        if ($mode === 2) {
            $unread = $this->receive($line, 0);
            $read = $this->receive($line, 1);
            if (empty($unread['success']) && empty($read['success'])) {
                return !empty($unread['error']) ? $unread : $read;
            }
            $entries = [];
            if (!empty($unread['success'])) {
                $entries = array_merge($entries, $this->entriesAsList($unread['entries']));
            }
            if (!empty($read['success'])) {
                $entries = array_merge($entries, $this->entriesAsList($read['entries']));
            }
            return [
                'success' => true,
                'entries' => $entries,
                'messageid' => null,
                'remaincredit' => null,
                'raw' => ['entries' => $entries],
            ];
        }

        $params = [
            'linenumber' => $line,
            'isread' => $mode === 1 ? 1 : 0,
        ];
        return $this->request('sms/receive.json', $params, 'GET');
    }

    public function countInbox($startDate, $endDate, $lineNumber = null, $isRead = null) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        $params = [
            'startdate' => intval($startDate),
            'enddate' => intval($endDate),
        ];
        $line = $this->resolveSender($lineNumber);
        if ($line === '') {
            return ['success' => false, 'error' => 'شماره خط دریافت‌کننده (Sender) الزامی است.'];
        }
        $params['linenumber'] = $line;
        if ($isRead !== null && $isRead !== '') {
            $params['isread'] = intval($isRead) === 1 ? 1 : 0;
        }
        return $this->request('sms/countinbox.json', $params, 'GET');
    }

    /**
     * Cancel a scheduled message.
     */
    public function cancel($messageids) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
        }
        $ids = $this->normalizeMessageIds($messageids);
        if ($ids === '') {
            return ['success' => false, 'error' => 'شناسه پیامک معتبر نیست.'];
        }
        return $this->request('sms/cancel.json', ['messageid' => $ids]);
    }

    private function resolveSender($sender = null) {
        $line = $sender !== null && trim((string)$sender) !== '' ? $sender : $this->sender;
        return self::normalizeSenderLine($line);
    }

    /**
     * Normalize Kavenegar line / sender (English digits only).
     * Example dedicated line: 9981803830
     */
    public static function normalizeSenderLine($raw) {
        $p = trim((string)$raw);
        if ($p === '') {
            return '';
        }
        $p = strtr($p, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $p = preg_replace('/[^\d]/', '', $p);
        return $p ?: '';
    }

    private function isIncompleteParamsError(array $result) {
        $msg = (string)($result['error'] ?? '');
        return (stripos($msg, 'ناقص') !== false) || (stripos($msg, 'incomplete') !== false) || intval($result['status'] ?? 0) === 424;
    }

    public static function isAccessDenied($message, $status = 0) {
        $status = intval($status);
        $msg = (string)$message;
        if (in_array($status, [413, 418], true)) {
            return true;
        }
        return (strpos($msg, 'دسترسی') !== false && strpos($msg, 'امکان') !== false)
            || stripos($msg, 'access') !== false;
    }

    public static function friendlyError($message, $status = 0) {
        if (self::isAccessDenied($message, $status)) {
            return 'حساب کاوه‌نگار شما به این بخش دسترسی ندارد (معمولاً در پلن فقط-اعتبارسنجی). تاریخچه سیستم و وضعیت تحویل پیامک‌های همین نرم‌افزار همچنان در دسترس است. برای صندوق خروجی/ورودی پنل، خط اختصاصی و دسترسی SMS در پنل کاوه‌نگار لازم است.';
        }
        return (string)$message;
    }

    private function entriesAsList($entries) {
        if (!is_array($entries)) {
            return [];
        }
        if (isset($entries['messageid']) && !isset($entries[0])) {
            return [$entries];
        }
        $out = [];
        foreach ($entries as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Normalize API entry list + attach Persian status labels.
     */
    public static function normalizeEntries($entries) {
        if (!is_array($entries)) {
            return [];
        }
        // account/info returns associative object, not list
        if (isset($entries['remaincredit']) || isset($entries['expiredate'])) {
            return $entries;
        }
        if (isset($entries['messageid']) && !isset($entries[0])) {
            $entries = [$entries];
        }
        $out = [];
        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = isset($row['status']) ? intval($row['status']) : null;
            $dateUnix = isset($row['date']) ? intval($row['date']) : null;
            $out[] = array_merge($row, [
                'messageid' => isset($row['messageid']) ? (string)$row['messageid'] : null,
                'status' => $status,
                'status_label' => $status !== null ? self::statusLabel($status) : null,
                'status_category' => $status !== null ? self::statusCategory($status) : 'unknown',
                'date_unix' => $dateUnix,
                'date_iso' => $dateUnix ? date('Y-m-d H:i:s', $dateUnix) : null,
            ]);
        }
        return $out;
    }

    private function normalizeMessageIds($messageids) {
        if (is_array($messageids)) {
            $parts = $messageids;
        } else {
            $parts = preg_split('/\s*,\s*/', (string)$messageids);
        }
        $clean = [];
        foreach ($parts as $id) {
            $id = preg_replace('/\D+/', '', (string)$id);
            if ($id !== '') {
                $clean[] = $id;
            }
        }
        $clean = array_slice(array_unique($clean), 0, 500);
        return implode(',', $clean);
    }

    private function request($method, $params = [], $httpMethod = 'POST') {
        $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($this->apiKey) . '/' . ltrim($method, '/');
        $ch = curl_init();
        if ($httpMethod === 'GET') {
            if (!empty($params)) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
            }
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['success' => false, 'error' => 'خطا در ارتباط با کاوه‌نگار: ' . $err];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['success' => false, 'error' => 'پاسخ نامعتبر از سرویس پیامک (HTTP ' . $http . ').'];
        }
        $status = intval($json['return']['status'] ?? 0);
        $message = $json['return']['message'] ?? 'خطای نامشخص';
        if ($status !== 200) {
            return [
                'success' => false,
                'error' => self::friendlyError($message, $status),
                'status' => $status,
                'raw' => $json,
                'access_denied' => self::isAccessDenied($message, $status),
            ];
        }
        $entries = $json['entries'] ?? [];
        $messageId = null;
        if (is_array($entries) && isset($entries[0]['messageid'])) {
            $messageId = (string)$entries[0]['messageid'];
        } elseif (is_array($entries) && isset($entries['messageid'])) {
            $messageId = (string)$entries['messageid'];
        }
        return [
            'success' => true,
            'entries' => $entries,
            'messageid' => $messageId,
            'remaincredit' => is_array($entries) ? ($entries['remaincredit'] ?? ($entries[0]['remaincredit'] ?? null)) : null,
            'raw' => $json,
        ];
    }
}
