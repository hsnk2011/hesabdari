<?php
// /api/core/cron_trigger.php
//
// "Poor man's cron" for shared hosting: piggy-backs on API requests.
// Scheduler::maybeTickFromRequest() returns immediately (no DB work) unless at
// least a minute passed since the last tick, and does nothing at all when
// settings.cron_mode = 'external' (a real cron calls /cron.php instead).
// Auto DB backup is intentionally NOT run here (it blocked the UI); the frontend
// runs it with a visible loader via run_pending_auto_backup.

try {
    require_once __DIR__ . '/Scheduler.php';
    if (isset($db) && $db instanceof mysqli) {
        Scheduler::maybeTickFromRequest($db);
    }
} catch (Throwable $e) {
    error_log("Request-triggered scheduler failed: " . $e->getMessage());
}
