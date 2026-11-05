// =================================================================
// APP LOGGER (client) — captures browser errors and sends to API
// =================================================================
const AppClientLogger = (function () {
    let enabled = true;
    let queue = [];
    let flushTimer = null;
    const MAX_QUEUE = 20;

    function report(level, message, extra = {}) {
        if (!enabled) return;
        try {
            queue.push({
                level: level || 'ERROR',
                message: String(message || 'Client error').slice(0, 2000),
                url: location.href,
                stack: extra.stack ? String(extra.stack).slice(0, 3000) : null,
                component: extra.component || null,
                extra: extra.extra || null
            });
            if (queue.length > MAX_QUEUE) {
                queue.shift();
            }
            scheduleFlush();
        } catch (e) {
            // never throw from logger
        }
    }

    function scheduleFlush() {
        if (flushTimer) return;
        flushTimer = setTimeout(flush, 800);
    }

    async function flush() {
        flushTimer = null;
        if (!queue.length) return;
        if (typeof Api === 'undefined' || !document.querySelector('meta[name="csrf-token"]')) {
            return;
        }
        const batch = queue.splice(0, 5);
        for (const item of batch) {
            try {
                // Avoid Api.call UI error toasts for logging itself
                const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                await fetch(`${AppConfig.API_URL}?action=report_client_error`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(item)
                });
            } catch (e) {
                // drop silently
            }
        }
        if (queue.length) scheduleFlush();
    }

    function init() {
        window.addEventListener('error', function (event) {
            report('ERROR', event.message || 'window.onerror', {
                component: 'window.onerror',
                stack: event.error && event.error.stack ? event.error.stack : null,
                extra: {
                    filename: event.filename,
                    lineno: event.lineno,
                    colno: event.colno
                }
            });
        });

        window.addEventListener('unhandledrejection', function (event) {
            const reason = event.reason;
            const message = reason && reason.message ? reason.message : String(reason || 'unhandledrejection');
            report('ERROR', message, {
                component: 'unhandledrejection',
                stack: reason && reason.stack ? reason.stack : null
            });
        });
    }

    return { init, report, flush };
})();
