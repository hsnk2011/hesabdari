<?php
// /api/core/Scheduler.php

/**
 * Lightweight job scheduler.
 *
 * Two entry points share the same task table:
 *  - /cron.php (CLI or URL with secret): the recommended "real" cron.
 *  - api/core/cron_trigger.php: a throttled fallback piggy-backing on user
 *    requests for shared hosts without cron. Disabled when cron_mode=external.
 *
 * Every task has its own interval; state lives in backups/scheduler_state.json
 * so that a request which is not due never opens a DB connection.
 */
class Scheduler {
    /** Minimum gap between two request-triggered ticks (seconds). */
    const REQUEST_TICK_GAP = 60;

    private static function stateFile() {
        return __DIR__ . '/../../backups/scheduler_state.json';
    }

    private static function lockFile() {
        return __DIR__ . '/../../backups/scheduler.lock';
    }

    /**
     * Task table: name => [interval seconds, callable(mysqli): mixed].
     */
    private static function tasks() {
        return [
            'integration_outbox' => [60, function ($db) {
                require_once __DIR__ . '/IntegrationService.php';
                return (new IntegrationService($db))->processOutbox(10);
            }],
            'online_payments_reconcile' => [600, function ($db) {
                require_once __DIR__ . '/../models/OnlinePayment.php';
                if (!method_exists('OnlinePayment', 'reconcilePending')) {
                    return null;
                }
                return OnlinePayment::reconcilePending($db);
            }],
            'partner_check_sms' => [900, function ($db) {
                require_once __DIR__ . '/../controllers/SmsController.php';
                return (new SmsController($db))->processPartnerCheckAlertsForAllEntities();
            }],
            'customer_reminders' => [900, function ($db) {
                require_once __DIR__ . '/../controllers/NotificationController.php';
                if (!method_exists('NotificationController', 'runScheduledReminders')) {
                    return null;
                }
                return NotificationController::runScheduledReminders($db);
            }],
            'crm_automation' => [3600, function ($db) {
                if (!workspace_enabled($db)) return 'workspace off';
                require_once __DIR__ . '/../models/CrmAutomation.php';
                return CrmAutomation::run($db);
            }],
            'work_task_reminders' => [900, function ($db) {
                if (!workspace_enabled($db)) return 'workspace off';
                require_once __DIR__ . '/../models/WorkReminders.php';
                return WorkReminders::dailyTasks($db);
            }],
            'work_meeting_reminders' => [60, function ($db) {
                if (!workspace_enabled($db)) return 'workspace off';
                require_once __DIR__ . '/../models/WorkReminders.php';
                return WorkReminders::meetings($db);
            }],
            'crm_push' => [60, function ($db) {
                if (!workspace_enabled($db)) return 'workspace off';
                require_once __DIR__ . '/../models/CrmAutomation.php';
                return CrmAutomation::sendDueReminders($db);
            }],
            'installments_overdue' => [3600, function ($db) {
                $db->query("UPDATE installments SET status = 'overdue' WHERE status = 'pending' AND due_date < CURDATE()");
                return $db->affected_rows;
            }],
            'quotations_expiry' => [3600, function ($db) {
                require_once __DIR__ . '/../models/Quotation.php';
                return Quotation::updateExpiredQuotations($db);
            }],
            'recurring_expenses' => [3600, function ($db) {
                $file = __DIR__ . '/../models/RecurringExpense.php';
                if (!is_file($file)) {
                    return null;
                }
                require_once $file;
                if (!class_exists('RecurringExpense')) {
                    return null;
                }
                return RecurringExpense::runDue($db);
            }],
            'integrity_fixes' => [21600, function ($db) {
                require_once __DIR__ . '/../models/BaseModel.php';
                return BaseModel::runIntegrityFixes($db);
            }],
            'asset_prices' => [3600, function ($db) {
                require_once __DIR__ . '/AssetPriceService.php';
                return (new AssetPriceService($db))->refresh(true);
            }],
            'idempotency_purge' => [43200, function ($db) {
                require_once __DIR__ . '/Idempotency.php';
                return Idempotency::purgeOld($db);
            }],
            'rate_limit_cleanup' => [3600, function ($db) {
                require_once __DIR__ . '/RateLimiter.php';
                RateLimiter::cleanup();
                return true;
            }],
        ];
    }

    private static function readState() {
        $file = self::stateFile();
        if (!is_file($file)) {
            return ['last_tick' => 0, 'tasks' => []];
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            return ['last_tick' => 0, 'tasks' => []];
        }
        $data += ['last_tick' => 0, 'tasks' => []];
        return $data;
    }

    private static function writeState(array $state) {
        @file_put_contents(self::stateFile(), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Called on every API request. Cheap when nothing is due.
     */
    public static function maybeTickFromRequest(mysqli $db) {
        $state = self::readState();
        if ((time() - (int)$state['last_tick']) < self::REQUEST_TICK_GAP) {
            return;
        }
        if (get_app_setting($db, 'cron_mode', 'request') === 'external') {
            return;
        }
        // Run after the user's response is sent so jobs (webhooks, SMS) never
        // add latency to the request that happened to trigger them.
        register_shutdown_function(function () {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                @flush();
            }
            ignore_user_abort(true);
            @set_time_limit(120);
            try {
                self::tick(false);
            } catch (Throwable $e) {
                error_log('Scheduler (shutdown) failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Run every due task (or all, when $force). Returns a per-task report.
     */
    public static function tick($force = false) {
        $report = [];
        $lock = @fopen(self::lockFile(), 'c+');
        if ($lock === false) {
            return ['error' => 'cannot open scheduler lock (check backups/ permissions)'];
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['skipped' => 'another scheduler run is in progress'];
        }

        // Jobs run with their own connection and without the caller's session
        // context, so a user's current entity never leaks into a background job.
        $savedSession = isset($_SESSION) ? $_SESSION : null;
        $cronDb = null;
        try {
            $state = self::readState();
            $state['last_tick'] = time();
            self::writeState($state);

            $now = time();
            foreach (self::tasks() as $name => $def) {
                list($interval, $fn) = $def;
                $last = (int)($state['tasks'][$name]['last_run'] ?? 0);
                if (!$force && ($now - $last) < $interval) {
                    continue;
                }
                if ($cronDb === null) {
                    $cronDb = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
                    $cronDb->set_charset('utf8mb4');
                    db_sync_timezone($cronDb);
                }
                $_SESSION = [];
                $started = microtime(true);
                try {
                    $result = $fn($cronDb);
                    $state['tasks'][$name] = [
                        'last_run' => $now,
                        'ok' => true,
                        'ms' => (int)round((microtime(true) - $started) * 1000),
                    ];
                    $report[$name] = $result;
                } catch (Throwable $e) {
                    $state['tasks'][$name] = [
                        'last_run' => $now,
                        'ok' => false,
                        'error' => mb_substr($e->getMessage(), 0, 300),
                    ];
                    $report[$name] = ['error' => $e->getMessage()];
                    error_log("Scheduler task {$name} failed: " . $e->getMessage());
                }
            }
            $state['last_tick'] = time();
            self::writeState($state);
        } finally {
            if ($savedSession === null) {
                $_SESSION = [];
            } else {
                $_SESSION = $savedSession;
            }
            if ($cronDb instanceof mysqli) {
                @$cronDb->close();
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $report;
    }

    /** Last-run info for the settings page. */
    public static function status() {
        return self::readState();
    }

    /**
     * Run $fn with $_SESSION['current_entity_id'] temporarily set. Models read
     * the entity from the session, so background jobs iterate entities this way.
     */
    public static function withEntity($entityId, callable $fn) {
        $prev = $_SESSION['current_entity_id'] ?? null;
        $prevAllowed = $_SESSION['allowed_entities'] ?? null;
        $_SESSION['current_entity_id'] = (int)$entityId;
        $_SESSION['allowed_entities'] = [(int)$entityId];
        try {
            return $fn();
        } finally {
            if ($prev === null) {
                unset($_SESSION['current_entity_id']);
            } else {
                $_SESSION['current_entity_id'] = $prev;
            }
            if ($prevAllowed === null) {
                unset($_SESSION['allowed_entities']);
            } else {
                $_SESSION['allowed_entities'] = $prevAllowed;
            }
        }
    }
}
