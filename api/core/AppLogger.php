<?php
/**
 * Structured application logger for debugging and error investigation.
 *
 * Writes JSON Lines to hesabdari/logs/app.log with levels, request id, user, action, and context.
 * Do not confuse with activity_log (user action audit) — this is for developers/admins diagnosing failures.
 */

class AppLogger {
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
    const CRITICAL = 'CRITICAL';

    private static $initialized = false;
    private static $logDir = null;
    private static $logFile = null;
    private static $requestId = null;
    private static $minLevel = self::INFO;
    private static $channel = 'app';
    private static $maxFileBytes = 5242880; // 5 MB
    private static $maxRotatedFiles = 10;
    private static $writing = false;

    private static $levelRank = [
        self::DEBUG => 10,
        self::INFO => 20,
        self::WARNING => 30,
        self::ERROR => 40,
        self::CRITICAL => 50,
    ];

    public static function init($options = []) {
        $isFirst = !self::$initialized;

        if ($isFirst) {
            self::$logDir = $options['dir'] ?? (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs');
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0755, true);
            }

            $htaccess = self::$logDir . DIRECTORY_SEPARATOR . '.htaccess';
            if (!is_file($htaccess)) {
                @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
            }
            $indexDeny = self::$logDir . DIRECTORY_SEPARATOR . 'index.html';
            if (!is_file($indexDeny)) {
                @file_put_contents($indexDeny, '');
            }

            self::$logFile = self::$logDir . DIRECTORY_SEPARATOR . 'app.log';
            self::$requestId = $options['request_id'] ?? self::generateRequestId();
            self::$channel = $options['channel'] ?? 'app';
            self::$maxFileBytes = intval($options['max_file_bytes'] ?? 5242880);
            self::$maxRotatedFiles = intval($options['max_rotated'] ?? 10);
            self::$initialized = true;
        } else {
            if (!empty($options['dir']) && $options['dir'] !== self::$logDir) {
                self::$logDir = $options['dir'];
                if (!is_dir(self::$logDir)) {
                    @mkdir(self::$logDir, 0755, true);
                }
                self::$logFile = self::$logDir . DIRECTORY_SEPARATOR . 'app.log';
            }
        }

        if (isset($options['min_level'])) {
            self::$minLevel = strtoupper($options['min_level']);
        } elseif ($isFirst) {
            if (defined('APP_DEBUG') && APP_DEBUG === true) {
                self::$minLevel = self::DEBUG;
            } else {
                self::$minLevel = self::INFO;
            }
        } elseif (defined('APP_DEBUG') && APP_DEBUG === true) {
            self::$minLevel = self::DEBUG;
        }
    }

    public static function getRequestId() {
        if (!self::$initialized) {
            self::init();
        }
        return self::$requestId;
    }

    public static function getLogDir() {
        if (!self::$initialized) {
            self::init();
        }
        return self::$logDir;
    }

    public static function getLogFile() {
        if (!self::$initialized) {
            self::init();
        }
        return self::$logFile;
    }

    public static function setChannel($channel) {
        self::$channel = $channel ?: 'app';
    }

    public static function debug($message, array $context = []) {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info($message, array $context = []) {
        self::log(self::INFO, $message, $context);
    }

    public static function warning($message, array $context = []) {
        self::log(self::WARNING, $message, $context);
    }

    public static function error($message, array $context = []) {
        self::log(self::ERROR, $message, $context);
    }

    public static function critical($message, array $context = []) {
        self::log(self::CRITICAL, $message, $context);
    }

    public static function exception(Throwable $e, $message = null, array $context = []) {
        $context['exception'] = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => self::formatTrace($e),
        ];
        $level = ($e instanceof Error) ? self::CRITICAL : self::ERROR;
        self::log($level, $message ?: ('Exception: ' . $e->getMessage()), $context);
    }

    public static function log($level, $message, array $context = []) {
        if (!self::$initialized) {
            self::init();
        }

        $level = strtoupper((string)$level);
        if (!isset(self::$levelRank[$level])) {
            $level = self::INFO;
        }
        if (self::$levelRank[$level] < (self::$levelRank[self::$minLevel] ?? 20)) {
            return;
        }

        // Prevent recursion if logging itself fails
        if (self::$writing) {
            return;
        }
        self::$writing = true;

        try {
            $entry = [
                'ts' => date('Y-m-d H:i:s'),
                'level' => $level,
                'channel' => self::$channel,
                'msg' => self::truncate((string)$message, 4000),
                'rid' => self::$requestId,
                'user_id' => isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null,
                'username' => $_SESSION['username'] ?? null,
                'entity_id' => isset($_SESSION['current_entity_id']) ? intval($_SESSION['current_entity_id']) : null,
                'action' => $_REQUEST['action'] ?? ($context['action'] ?? null),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'uri' => isset($_SERVER['REQUEST_URI']) ? self::truncate($_SERVER['REQUEST_URI'], 500) : null,
            ];

            if (!empty($context)) {
                $entry['ctx'] = self::sanitizeContext($context);
            }

            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                $line = json_encode([
                    'ts' => date('Y-m-d H:i:s'),
                    'level' => $level,
                    'msg' => 'Failed to encode log entry',
                    'rid' => self::$requestId,
                ]);
            }

            self::rotateIfNeeded();
            @file_put_contents(self::$logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } finally {
            self::$writing = false;
        }
    }

    /**
     * Register PHP error / exception / shutdown handlers that feed AppLogger.
     */
    public static function registerHandlers() {
        if (!self::$initialized) {
            self::init();
        }

        set_error_handler(function ($severity, $message, $file, $line) {
            // Respect @-suppressed errors
            if (!(error_reporting() & $severity)) {
                return false;
            }
            $map = [
                E_ERROR => self::CRITICAL,
                E_WARNING => self::WARNING,
                E_PARSE => self::CRITICAL,
                E_NOTICE => self::DEBUG,
                E_CORE_ERROR => self::CRITICAL,
                E_CORE_WARNING => self::WARNING,
                E_COMPILE_ERROR => self::CRITICAL,
                E_COMPILE_WARNING => self::WARNING,
                E_USER_ERROR => self::ERROR,
                E_USER_WARNING => self::WARNING,
                E_USER_NOTICE => self::INFO,
                E_STRICT => self::DEBUG,
                E_RECOVERABLE_ERROR => self::ERROR,
                E_DEPRECATED => self::DEBUG,
                E_USER_DEPRECATED => self::DEBUG,
            ];
            $level = $map[$severity] ?? self::WARNING;
            self::log($level, $message, [
                'php_errno' => $errno,
                'file' => $file,
                'line' => $line,
                'source' => 'php_error_handler',
            ]);
            // Let PHP's normal handling continue (and php_errors.log via ini)
            return false;
        });

        set_exception_handler(function ($e) {
            self::exception($e, 'Uncaught exception', ['source' => 'exception_handler']);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            $msg = (defined('APP_DEBUG') && APP_DEBUG)
                ? $e->getMessage()
                : 'خطای داخلی سرور. لطفاً دوباره تلاش کنید.';
            echo json_encode(['error' => $msg, 'request_id' => self::$requestId], JSON_UNESCAPED_UNICODE);
            exit(1);
        });

        register_shutdown_function(function () {
            $err = error_get_last();
            if (!$err) {
                return;
            }
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($err['type'], $fatalTypes, true)) {
                return;
            }
            self::critical($err['message'], [
                'php_errno' => $err['type'],
                'file' => $err['file'],
                'line' => $err['line'],
                'source' => 'shutdown_fatal',
            ]);
        });
    }

    /**
     * Read recent log entries (newest first) with optional filters.
     *
     * @return array{entries: array, total_scanned: int, file_size: int, file_mtime: int|null}
     */
    public static function readEntries($options = []) {
        if (!self::$initialized) {
            self::init();
        }

        $limit = max(1, min(500, intval($options['limit'] ?? 100)));
        $level = isset($options['level']) ? strtoupper(trim($options['level'])) : '';
        $search = isset($options['search']) ? trim((string)$options['search']) : '';
        $channel = isset($options['channel']) ? trim((string)$options['channel']) : '';
        $offset = max(0, intval($options['offset'] ?? 0));

        $file = self::$logFile;
        $fileSize = is_file($file) ? filesize($file) : 0;
        $fileMtime = is_file($file) ? filemtime($file) : null;

        if ($fileSize === 0 || !is_readable($file)) {
            return [
                'entries' => [],
                'total_scanned' => 0,
                'file_size' => 0,
                'file_mtime' => $fileMtime,
                'request_id' => self::$requestId,
            ];
        }

        // Read from end for efficiency (last ~2MB or whole file if smaller)
        $readBytes = min($fileSize, 2 * 1024 * 1024);
        $fh = fopen($file, 'rb');
        if (!$fh) {
            return [
                'entries' => [],
                'total_scanned' => 0,
                'file_size' => $fileSize,
                'file_mtime' => $fileMtime,
                'request_id' => self::$requestId,
            ];
        }
        fseek($fh, -$readBytes, SEEK_END);
        $chunk = stream_get_contents($fh);
        fclose($fh);

        $lines = preg_split("/\r\n|\n|\r/", $chunk);
        // If we started mid-line, drop first partial line
        if ($readBytes < $fileSize && !empty($lines)) {
            array_shift($lines);
        }

        $matched = [];
        $scanned = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $raw = trim($lines[$i]);
            if ($raw === '') {
                continue;
            }
            $scanned++;
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = [
                    'ts' => null,
                    'level' => 'INFO',
                    'msg' => $raw,
                    'channel' => 'raw',
                ];
            }

            if ($level !== '' && strtoupper($decoded['level'] ?? '') !== $level) {
                continue;
            }
            if ($channel !== '' && ($decoded['channel'] ?? '') !== $channel) {
                continue;
            }
            if ($search !== '') {
                $hay = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                if (mb_stripos($hay, $search) === false) {
                    continue;
                }
            }

            $matched[] = $decoded;
        }

        $totalMatched = count($matched);
        $entries = array_slice($matched, $offset, $limit);

        return [
            'entries' => $entries,
            'total_matched' => $totalMatched,
            'total_scanned' => $scanned,
            'file_size' => $fileSize,
            'file_mtime' => $fileMtime,
            'request_id' => self::$requestId,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public static function getStats() {
        if (!self::$initialized) {
            self::init();
        }

        $result = self::readEntries(['limit' => 500]);
        $counts = [
            self::DEBUG => 0,
            self::INFO => 0,
            self::WARNING => 0,
            self::ERROR => 0,
            self::CRITICAL => 0,
        ];
        foreach ($result['entries'] as $e) {
            $lvl = strtoupper($e['level'] ?? 'INFO');
            if (isset($counts[$lvl])) {
                $counts[$lvl]++;
            }
        }

        $rotated = glob(self::$logDir . DIRECTORY_SEPARATOR . 'app.log.*') ?: [];

        return [
            'counts' => $counts,
            'file_size' => $result['file_size'],
            'file_mtime' => $result['file_mtime'],
            'rotated_files' => count($rotated),
            'log_path' => self::$logFile,
            'min_level' => self::$minLevel,
            'app_debug' => defined('APP_DEBUG') ? (bool)APP_DEBUG : false,
        ];
    }

    public static function clear($keepLastLines = 0) {
        if (!self::$initialized) {
            self::init();
        }
        $keepLastLines = max(0, intval($keepLastLines));
        if (!is_file(self::$logFile)) {
            return true;
        }
        if ($keepLastLines === 0) {
            return @file_put_contents(self::$logFile, '', LOCK_EX) !== false;
        }
        $data = self::readEntries(['limit' => $keepLastLines]);
        $lines = [];
        foreach (array_reverse($data['entries']) as $e) {
            $lines[] = json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $body = empty($lines) ? '' : (implode(PHP_EOL, $lines) . PHP_EOL);
        return @file_put_contents(self::$logFile, $body, LOCK_EX) !== false;
    }

    private static function rotateIfNeeded() {
        if (!is_file(self::$logFile)) {
            return;
        }
        clearstatcache(true, self::$logFile);
        $size = @filesize(self::$logFile);
        if ($size === false || $size < self::$maxFileBytes) {
            return;
        }
        $stamp = date('Ymd_His');
        $target = self::$logFile . '.' . $stamp;
        @rename(self::$logFile, $target);

        $rotated = glob(self::$logDir . DIRECTORY_SEPARATOR . 'app.log.*') ?: [];
        usort($rotated, function ($a, $b) {
            return filemtime($a) <=> filemtime($b);
        });
        while (count($rotated) > self::$maxRotatedFiles) {
            $old = array_shift($rotated);
            @unlink($old);
        }
    }

    private static function generateRequestId() {
        try {
            return bin2hex(random_bytes(8));
        } catch (Exception $e) {
            return uniqid('r', true);
        }
    }

    private static function formatTrace(Throwable $e) {
        $frames = [];
        foreach ($e->getTrace() as $i => $frame) {
            if ($i >= 20) {
                $frames[] = '...';
                break;
            }
            $file = isset($frame['file']) ? basename($frame['file']) : '[internal]';
            $line = $frame['line'] ?? '?';
            $fn = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $frames[] = "#{$i} {$file}:{$line} {$fn}()";
        }
        return implode("\n", $frames);
    }

    private static function sanitizeContext(array $context) {
        $sensitive = ['password', 'pass', 'token', 'csrf', 'secret', 'api_key', 'authorization', 'card', 'cvv'];
        $out = [];
        foreach ($context as $key => $value) {
            $lk = strtolower((string)$key);
            foreach ($sensitive as $s) {
                if (strpos($lk, $s) !== false) {
                    $out[$key] = '[redacted]';
                    continue 2;
                }
            }
            if (is_array($value)) {
                $out[$key] = self::sanitizeContext($value);
            } elseif (is_object($value)) {
                $out[$key] = '[object ' . get_class($value) . ']';
            } elseif (is_string($value)) {
                $out[$key] = self::truncate($value, 2000);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private static function truncate($str, $max) {
        if (mb_strlen($str) <= $max) {
            return $str;
        }
        return mb_substr($str, 0, $max) . '…';
    }
}
