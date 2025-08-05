// =================================================================
// API MODULE (js/api.js)
// =================================================================
const Api = (function () {
    async function call(action, data = {}, handleAuthError = true) {
        try {
            const response = await fetch(`${AppConfig.API_URL}?action=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const responseData = await response.json().catch(() => ({ error: 'پاسخ دریافتی از سرور معتبر نیست (Non-JSON).' }));

            if (!response.ok) {
                if (response.status === 401 && handleAuthError) {
                    alert('نشست شما خاتمه یافته است. لطفاً مجدداً وارد شوید.');
                    location.reload();
                    return null;
                }
                const errorMessage = responseData?.error ? `${responseData.error}: ${responseData.message}` : `Action not found: ${action}`;
                throw new Error(errorMessage);
            }

            if (responseData && responseData.error) {
                const detailedError = `${responseData.error}: ${responseData.message || 'No details'}\nFile: ${responseData.file || 'N/A'}\nLine: ${responseData.line || 'N/A'}`;
                throw new Error(detailedError);
            }
            return responseData;
        } catch (error) {
            console.error('API Call Error:', { action, error });
            alert(`خطا در ارتباط با سرور: ${error.message}`);
            return null;
        }
    }

    return {
        call: call
    };
})();