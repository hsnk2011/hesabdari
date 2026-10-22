// Camera scan of a Sayad cheque barcode. The barcode carries the 16-digit Sayad id only.
const SayadScanner = (function () {
    const WANTED_FORMATS = ['qr_code', 'code_128', 'pdf417', 'data_matrix', 'code_39', 'itf'];

    let overlay = null;
    let video = null;
    let statusEl = null;
    let stream = null;
    let timer = null;
    let detector = null;
    let targetInput = null;
    let running = false;
    let lastReject = '';
    let polyfillPromise = null;

    function toEnglishDigits(value) {
        return String(value == null ? '' : value)
            .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
            .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
    }

    function extractSayadId(raw) {
        const text = toEnglishDigits(raw).trim();
        if (!text) return null;
        const bounded = text.match(/(?:^|\D)(\d{16})(?!\d)/);
        if (bounded) return bounded[1];
        const digits = text.replace(/\D/g, '');
        return digits.length === 16 ? digits : null;
    }

    function ensureOverlay() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'sayad-scan-overlay';
        overlay.hidden = true;
        overlay.innerHTML = `
            <div class="sayad-scan-panel" role="dialog" aria-modal="true" aria-labelledby="sayad-scan-title">
                <div class="sayad-scan-head">
                    <h6 id="sayad-scan-title" class="mb-0">اسکن بارکد چک صیادی</h6>
                    <button type="button" class="btn-close sayad-scan-close" aria-label="بستن"></button>
                </div>
                <div class="sayad-scan-stage">
                    <video class="sayad-scan-video" autoplay playsinline muted></video>
                    <div class="sayad-scan-frame" aria-hidden="true"></div>
                </div>
                <p class="sayad-scan-status mb-0"></p>
            </div>`;
        document.body.appendChild(overlay);
        video = overlay.querySelector('.sayad-scan-video');
        statusEl = overlay.querySelector('.sayad-scan-status');
        overlay.querySelector('.sayad-scan-close').addEventListener('click', close);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) close();
        });
    }

    function setStatus(text) {
        if (statusEl) statusEl.textContent = text;
    }

    function loadPolyfill() {
        if (typeof BarcodeDetector !== 'undefined') return Promise.resolve();
        if (polyfillPromise) return polyfillPromise;
        polyfillPromise = new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            const version = window.__APP_VERSION__ || '1';
            script.src = 'assets/js/barcode-detector-polyfill.min.js?v=' + encodeURIComponent(version);
            script.onload = function () {
                if (typeof BarcodeDetector === 'undefined') reject(new Error('polyfill'));
                else resolve();
            };
            script.onerror = function () { reject(new Error('polyfill')); };
            document.head.appendChild(script);
        });
        return polyfillPromise;
    }

    function cameraErrorMessage(error) {
        const name = error && error.name;
        if (name === 'NotAllowedError' || name === 'SecurityError') {
            return 'دسترسی به دوربین داده نشد. در تنظیمات مرورگر اجازه دوربین را فعال کنید.';
        }
        if (name === 'NotFoundError' || name === 'OverconstrainedError') {
            return 'دوربینی پیدا نشد.';
        }
        if (name === 'NotReadableError') {
            return 'دوربین در حال استفاده برنامه دیگری است.';
        }
        return 'باز کردن دوربین ممکن نشد.';
    }

    function stopTracks() {
        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            stream = null;
        }
        if (video) video.srcObject = null;
    }

    function close() {
        running = false;
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
        stopTracks();
        detector = null;
        targetInput = null;
        lastReject = '';
        if (overlay) overlay.hidden = true;
    }

    async function startCamera() {
        const attempts = [
            { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false },
            { video: true, audio: false }
        ];
        let lastError = null;
        for (let i = 0; i < attempts.length; i++) {
            try {
                stream = await navigator.mediaDevices.getUserMedia(attempts[i]);
                video.srcObject = stream;
                await video.play();
                return;
            } catch (error) {
                lastError = error;
                stopTracks();
                if (error && (error.name === 'NotAllowedError' || error.name === 'SecurityError')) break;
            }
        }
        throw lastError || new Error('camera');
    }

    function applyId(id) {
        const input = targetInput;
        close();
        if (input) {
            input.value = id;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
        }
        if (typeof UI !== 'undefined' && UI.showSuccess) {
            UI.showSuccess('شناسه صیاد در شماره چک قرار گرفت.');
        }
    }

    async function tick() {
        if (!running) return;
        try {
            if (detector && video && video.readyState >= 2) {
                const codes = await detector.detect(video);
                for (let i = 0; i < codes.length; i++) {
                    const raw = codes[i] && codes[i].rawValue;
                    const id = extractSayadId(raw);
                    if (id) {
                        applyId(id);
                        return;
                    }
                    const shown = String(raw || '').trim();
                    if (shown && shown !== lastReject) {
                        lastReject = shown;
                        setStatus('این بارکد شناسه ۱۶ رقمی صیاد نیست. بارکد کنار شناسه را بگیرید.');
                    }
                }
            }
        } catch (e) { /* next frame */ }
        if (running) timer = setTimeout(tick, 280);
    }

    async function open(input) {
        if (!input || input.disabled) return;
        close();
        targetInput = input;
        ensureOverlay();
        overlay.hidden = false;
        setStatus('در حال آماده‌سازی دوربین...');

        if (!window.isSecureContext) {
            setStatus('مرورگر فقط روی localhost یا HTTPS به دوربین اجازه می‌دهد.');
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus('این مرورگر به دوربین دسترسی ندارد.');
            return;
        }

        try {
            await loadPolyfill();
            const supported = typeof BarcodeDetector.getSupportedFormats === 'function'
                ? await BarcodeDetector.getSupportedFormats()
                : WANTED_FORMATS;
            const formats = WANTED_FORMATS.filter(function (format) { return supported.indexOf(format) !== -1; });
            detector = formats.length
                ? new BarcodeDetector({ formats: formats })
                : new BarcodeDetector();
            await startCamera();
            running = true;
            setStatus('بارکد مربعی شناسه صیاد را داخل کادر بگیرید.');
            tick();
        } catch (error) {
            running = false;
            stopTracks();
            if (error && error.message === 'polyfill') {
                setStatus('خواندن بارکد بارگذاری نشد. صفحه را تازه کنید و دوباره اسکن کنید.');
            } else {
                setStatus(cameraErrorMessage(error));
            }
        }
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && overlay && !overlay.hidden) {
            event.preventDefault();
            event.stopPropagation();
            close();
        }
    }, true);

    $(document).on('click', '.scan-sayad-btn', function (event) {
        event.preventDefault();
        const field = this.closest('.pay-col-check-no') || this.parentElement;
        const input = field ? field.querySelector('.check-number') : null;
        open(input);
    });

    return { open: open, extractSayadId: extractSayadId };
})();
