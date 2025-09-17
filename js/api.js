// =================================================================
// API MODULE (js/api.js)
// =================================================================
const Api = (function () {
    async function call(action, data = {}, handleAuthError = true) {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            const response = await fetch(`${AppConfig.API_URL}?action=${action}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken // Add CSRF token to headers
                },
                body: JSON.stringify(data)
            });

            const responseData = await response.json().catch(() => ({ error: 'پاسخ دریافتی از سرور معتبر نیست (Non-JSON).' }));

            if (!response.ok) {
                if (response.status === 401 && handleAuthError) {
                    UI.showError('نشست شما خاتمه یافته است. لطفاً مجدداً وارد شوید.');
                    setTimeout(() => location.reload(), 2000);
                    return null;
                }
                // IMPROVEMENT: Use a more user-friendly error from the server response if available.
                const errorMessage = responseData?.error ? `${responseData.error}` : `خطای سرور: ${response.statusText} (${response.status})`;
                throw new Error(errorMessage);
            }

            // Update CSRF token if the server sends a new one (e.g., after login)
            if (responseData && responseData.csrf_token) {
                document.querySelector('meta[name="csrf-token"]').setAttribute('content', responseData.csrf_token);
            }

            // Handle application-level errors returned in a 2xx response
            if (responseData && responseData.error && action !== 'login') {
                const detailedError = `${responseData.error}`;
                throw new Error(detailedError);
            }
            return responseData;
        } catch (error) {
            console.error('API Call Error:', { action, error });
            UI.showError(`خطا در ارتباط با سرور: ${error.message}`);
            return null;
        }
    }

    return {
        call: call
    };
})();