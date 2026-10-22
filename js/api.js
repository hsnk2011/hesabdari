// =================================================================
// API MODULE (js/api.js)
// =================================================================
const Api = (function () {
    const activeRequests = new Map();
    let tokenRefreshPromise = null;
    let lastRateLimitNoticeAt = 0;

    function sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    // One toast for a burst of 429s. The server limit is a sliding window, so
    // a short wait is enough; don't stack an error on every in-flight call.
    function notifyRateLimit() {
        const now = Date.now();
        if (now - lastRateLimitNoticeAt < 8000) return;
        lastRateLimitNoticeAt = now;
        UI.showError('تعداد درخواست‌ها موقتاً زیاد است. چند ثانیه دیگر دوباره تلاش کنید.');
    }

    async function fetchWithRateLimitRetry(url, options) {
        let response = await fetch(url, options);
        if (response.status !== 429) return response;
        const retryAfter = parseInt(response.headers.get('Retry-After') || '1', 10);
        const waitSec = Number.isFinite(retryAfter) ? Math.min(Math.max(retryAfter, 1), 3) : 1;
        await sleep(waitSec * 1000);
        response = await fetch(url, options);
        return response;
    }

    // Refresh the CSRF token from the server (check_session echoes the session's
    // token). Shared promise so a burst of failed requests triggers one refresh.
    function refreshCsrfToken() {
        if (!tokenRefreshPromise) {
            tokenRefreshPromise = (async () => {
                try {
                    const response = await fetch(`${AppConfig.API_URL}?action=check_session`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: '{}'
                    });
                    const data = await response.json().catch(() => null);
                    if (data && data.csrf_token) {
                        document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', data.csrf_token);
                        return true;
                    }
                } catch (e) { /* fall through */ }
                return false;
            })().finally(() => { tokenRefreshPromise = null; });
        }
        return tokenRefreshPromise;
    }

    let workspaceOffPromise = null;
    function refreshAccessAfterWorkspaceOff() {
        if (!workspaceOffPromise) {
            workspaceOffPromise = (async () => {
                const session = await call('check_session', {}, false);
                if (session && session.permissions && typeof App !== 'undefined' && App.updateAccess) {
                    App.updateAccess(session);
                }
            })().finally(() => { workspaceOffPromise = null; });
        }
        return workspaceOffPromise;
    }

    async function call(action, data = {}, handleAuthError = true, retried = false) {
        let requestKey;
        try {
            requestKey = action + '|' + JSON.stringify(data);
        } catch (e) {
            requestKey = action;
        }

        // A retry (after a CSRF refresh) runs inside the original request: sharing the
        // in-flight promise there would make it wait for itself forever.
        if (!retried && activeRequests.has(requestKey)) {
            // Share the in-flight promise (dedupe), not an error — callers should
            // not treat this as a failure or log noise in the console.
            return activeRequests.get(requestKey);
        }

        const promise = (async () => {
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                const headers = {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                };
                // One key per form submission: the server replays the first
                // successful result instead of creating a duplicate document.
                if (data && data._idempotency_key) {
                    headers['X-Idempotency-Key'] = String(data._idempotency_key);
                }
                const response = await fetchWithRateLimitRetry(`${AppConfig.API_URL}?action=${action}`, {
                    method: 'POST',
                    headers,
                    body: JSON.stringify(data)
                });

                const responseData = await response.json().catch(() => ({ error: 'پاسخ دریافتی از سرور معتبر نیست (Non-JSON).' }));

                if (!response.ok) {
                    if (response.status === 401 && handleAuthError) {
                        UI.showError('نشست شما خاتمه یافته است. لطفاً مجدداً وارد شوید.');
                        setTimeout(() => location.reload(), 2000);
                        return null;
                    }
                    if (response.status === 403 && responseData?.workspace_disabled) {
                        // «میز کار» was switched off after this page loaded: drop its tab and polling quietly.
                        refreshAccessAfterWorkspaceOff();
                        return null;
                    }
                    if (response.status === 403) {
                        // A 403 can mean "stale CSRF token" (e.g. page HTML served
                        // from a cache) rather than a real permission denial.
                        // Refresh the token once and retry before giving up.
                        if (!retried && action !== 'check_session') {
                            const refreshed = await refreshCsrfToken();
                            if (refreshed) {
                                return call(action, data, handleAuthError, true);
                            }
                        }
                        UI.showError(responseData?.error || 'شما دسترسی به این عملیات را ندارید.');
                        return null;
                    }
                    if (response.status === 404) {
                        UI.showError('مورد درخواستی یافت نشد.');
                        return null;
                    }
                    if (response.status === 429) {
                        notifyRateLimit();
                        return null;
                    }
                    if (response.status >= 500) {
                        if (typeof AppClientLogger !== 'undefined') {
                            AppClientLogger.report('ERROR', responseData?.error || `HTTP ${response.status}`, {
                                component: 'api.call',
                                extra: { action, status: response.status, request_id: responseData?.request_id || null }
                            });
                        }
                        const rid = responseData?.request_id ? ` (کد: ${responseData.request_id})` : '';
                        // Many business rules (duplicate check number, sold items, closed period, ...)
                        // come back as 500 with a user-readable message — show it instead of a
                        // generic text that hides why the save failed.
                        const serverMsg = (responseData && typeof responseData.error === 'string') ? responseData.error.trim() : '';
                        const isGeneric = !serverMsg || serverMsg.indexOf('خطای داخلی سرور') === 0 || serverMsg.indexOf('Non-JSON') !== -1;
                        UI.showError((isGeneric ? 'خطای سرور رخ داده است. لطفاً با پشتیبانی تماس بگیرید.' : serverMsg) + rid);
                        return null;
                    }
                    const errorMessage = responseData?.error ? `${responseData.error}` : `خطای سرور: ${response.statusText} (${response.status})`;
                    throw new Error(errorMessage);
                }

                if (responseData && responseData.csrf_token) {
                    document.querySelector('meta[name="csrf-token"]').setAttribute('content', responseData.csrf_token);
                }

                if (responseData && responseData.error && action !== 'login') {
                    throw new Error(responseData.error);
                }
                return responseData;
            } catch (error) {
                // Handle network errors specifically
                let errorMessage = error.message;
                if (error.name === 'TypeError' && error.message.includes('fetch')) {
                    errorMessage = 'اتصال به سرور برقرار نشد. لطفاً اتصال اینترنت خود را بررسی کنید.';
                } else if (error.name === 'AbortError') {
                    errorMessage = 'درخواست به سرور لغو شد.';
                }
                UI.showError(`خطا در ارتباط با سرور: ${errorMessage}`);
                return null;
            } finally {
                activeRequests.delete(requestKey);
            }
        })();

        activeRequests.set(requestKey, promise);
        return promise;
    }

    /**
     * آپلود فایل با FormData
     */
    async function uploadFile(action, formData, handleAuthError = true) {
        const requestKey = action + '|upload';
        
        if (activeRequests.has(requestKey)) {
            console.warn('Duplicate upload prevented:', action);
            return Promise.resolve(null);
        }

        const promise = (async () => {
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                formData.append('csrf_token', csrfToken);

                const response = await fetchWithRateLimitRetry(`${AppConfig.API_URL}?action=${action}`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: formData
                });

                const responseData = await response.json().catch(() => ({ error: 'پاسخ دریافتی از سرور معتبر نیست.' }));

                if (!response.ok) {
                    if (response.status === 401 && handleAuthError) {
                        UI.showError('نشست شما خاتمه یافته است. لطفاً مجدداً وارد شوید.');
                        setTimeout(() => location.reload(), 2000);
                        return null;
                    }
                    if (response.status === 429) {
                        notifyRateLimit();
                        return null;
                    }
                    throw new Error(responseData?.error || `خطای سرور: ${response.status}`);
                }

                if (responseData && responseData.csrf_token) {
                    document.querySelector('meta[name="csrf-token"]').setAttribute('content', responseData.csrf_token);
                }

                return responseData;
            } catch (error) {
                UI.showError(`خطا در آپلود فایل: ${error.message}`);
                return null;
            } finally {
                activeRequests.delete(requestKey);
            }
        })();

        activeRequests.set(requestKey, promise);
        return promise;
    }

    return {
        call: call,
        uploadFile: uploadFile
    };
})();