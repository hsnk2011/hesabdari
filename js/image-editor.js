/**
 * ویرایشگر کامل تصویر محصول (مشابه مدیریت سایت زیما هوم)
 * کراپ مستطیلی، پرسپکتیو، چرخش، قرینه، حذف پس‌زمینه، پس‌زمینه خروجی
 */
window.ProductImageEditor = (function ($) {
    'use strict';

    const MODEL_OPTIONS = [
        { value: 'isnet-general-use', label: 'عمومی (پیشنهادی)' },
        { value: 'birefnet-general-lite', label: 'کیفیت بالا — سبک' },
        { value: 'birefnet-general', label: 'کیفیت بالا' },
        { value: 'u2net', label: 'u2net' },
        { value: 'u2netp', label: 'u2net سبک' },
        { value: 'silueta', label: 'silueta' },
    ];

    const ASPECTS = [
        { value: 'free', label: 'آزاد', ratio: null },
        { value: '2/3', label: '۲:۳', ratio: 2 / 3 },
        { value: '3/4', label: '۳:۴', ratio: 3 / 4 },
        { value: '1/1', label: '۱:۱', ratio: 1 },
        { value: '4/5', label: '۴:۵', ratio: 4 / 5 },
        { value: '16/9', label: '۱۶:۹', ratio: 16 / 9 },
    ];

    const HANDLE_HIT = 14;
    let state = null;
    let dragCorner = null;
    let sessionSeq = 0;
    let closingSessionId = null;

    function ensureModal() {
        if ($('#productImageEditorModal').length) return;
        const modelOpts = MODEL_OPTIONS.map(
            (m) => `<option value="${m.value}">${m.label}</option>`
        ).join('');
        const aspectBtns = ASPECTS.map(
            (a) =>
                `<button type="button" class="btn btn-sm btn-outline-secondary pie-aspect" data-aspect="${a.value}">${a.label}</button>`
        ).join('');

        $('body').append(`
<div class="modal fade" id="productImageEditorModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="pie-modal-title">ویرایش تصویر</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-lg-8">
            <div id="pie-stage" class="border rounded position-relative bg-dark d-flex align-items-center justify-content-center overflow-hidden"
                 style="min-height:360px;max-height:62vh;background-image:linear-gradient(45deg,#334155 25%,transparent 25%),linear-gradient(-45deg,#334155 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#334155 75%),linear-gradient(-45deg,transparent 75%,#334155 75%);background-size:20px 20px;background-position:0 0,0 10px,10px -10px,-10px 0;background-color:#1e293b;">
              <div id="pie-canvas-wrap" class="position-relative d-inline-block">
                <canvas id="pie-canvas" style="max-width:100%;max-height:58vh;display:block;"></canvas>
                <div id="pie-rect-crop" class="position-absolute border border-success" style="display:none;box-shadow:0 0 0 9999px rgba(0,0,0,.45);cursor:move;touch-action:none;">
                  <span class="pie-crop-handle" data-corner="nw" style="position:absolute;width:12px;height:12px;background:#22c55e;border:2px solid #fff;border-radius:2px;left:-6px;top:-6px;cursor:nwse-resize;touch-action:none;"></span>
                  <span class="pie-crop-handle" data-corner="ne" style="position:absolute;width:12px;height:12px;background:#22c55e;border:2px solid #fff;border-radius:2px;right:-6px;top:-6px;cursor:nesw-resize;touch-action:none;"></span>
                  <span class="pie-crop-handle" data-corner="se" style="position:absolute;width:12px;height:12px;background:#22c55e;border:2px solid #fff;border-radius:2px;right:-6px;bottom:-6px;cursor:nwse-resize;touch-action:none;"></span>
                  <span class="pie-crop-handle" data-corner="sw" style="position:absolute;width:12px;height:12px;background:#22c55e;border:2px solid #fff;border-radius:2px;left:-6px;bottom:-6px;cursor:nesw-resize;touch-action:none;"></span>
                </div>
                <svg id="pie-persp-svg" class="position-absolute top-0 start-0 w-100 h-100" style="display:none;pointer-events:none;overflow:visible;"></svg>
              <div id="pie-persp-handles" style="display:none;touch-action:none;"></div>
              </div>
              <div id="pie-busy" class="position-absolute top-0 start-0 w-100 h-100 d-none align-items-center justify-content-center" style="background:rgba(15,23,42,.55);z-index:20;">
                <div class="spinner-border text-light" role="status"></div>
              </div>
            </div>
            <p class="small text-muted mt-2 mb-0" id="pie-hint">برای کراپ، یکی از نسبت‌ها را انتخاب کنید. در غیر این صورت کل تصویر ذخیره می‌شود.</p>
          </div>
          <div class="col-lg-4">
            <div class="border rounded p-3 mb-3">
              <div class="fw-semibold mb-2"><i class="bi bi-stars text-success"></i> حذف پس‌زمینه</div>
              <select id="pie-model" class="form-select form-select-sm mb-2">${modelOpts}</select>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-success" id="pie-rembg-now"><i class="bi bi-eraser"></i> حذف همین الان</button>
              </div>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="pie-rembg-onsave">
                <label class="form-check-label small" for="pie-rembg-onsave">هنگام ذخیره</label>
              </div>
              <div id="pie-rembg-status" class="small text-muted mt-1"></div>
            </div>

            <div class="border rounded p-3 mb-3">
              <div class="fw-semibold mb-2"><i class="bi bi-arrow-clockwise text-success"></i> چرخش و قرینه</div>
              <div class="d-flex flex-wrap gap-1 mb-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="pie-rot-m90">۹۰-</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="pie-rot-p90">۹۰+</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="pie-flip-h">افقی</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="pie-flip-v">عمودی</button>
              </div>
              <input type="range" class="form-range" id="pie-rot-range" min="-180" max="180" value="0">
              <div class="small text-muted text-center" id="pie-rot-label">0°</div>
            </div>

            <div class="border rounded p-3 mb-3">
              <div class="fw-semibold mb-2"><i class="bi bi-crop text-success"></i> کراپ</div>
              <div class="btn-group btn-group-sm mb-2" role="group">
                <button type="button" class="btn btn-success pie-crop-mode" data-mode="rect">مستطیلی</button>
                <button type="button" class="btn btn-outline-secondary pie-crop-mode" data-mode="perspective">پرسپکتیو</button>
              </div>
              <div id="pie-aspect-row" class="d-flex flex-wrap gap-1 mb-2">${aspectBtns}</div>
              <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-1" id="pie-clear-crop">حذف کراپ (کل تصویر)</button>
              <button type="button" class="btn btn-sm btn-primary w-100" id="pie-apply-persp" style="display:none;">اعمال پرسپکتیو</button>
            </div>

            <div class="border rounded p-3 mb-3">
              <div class="fw-semibold mb-2">پس‌زمینه خروجی</div>
              <div class="btn-group btn-group-sm flex-wrap" role="group">
                <button type="button" class="btn btn-outline-secondary pie-bg" data-bg="transparent">شفاف</button>
                <button type="button" class="btn btn-success pie-bg" data-bg="white">سفید</button>
                <button type="button" class="btn btn-outline-secondary pie-bg" data-bg="custom">دلخواه</button>
              </div>
              <input type="color" id="pie-bg-color" class="form-control form-control-color mt-2" value="#ffffff" style="display:none;width:100%;">
            </div>

            <div class="border rounded p-3 mb-3" id="pie-save-mode-box" style="display:none;">
              <div class="fw-semibold mb-2">نحوه ذخیره</div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="pie-save-mode" id="pie-save-new" value="new" checked>
                <label class="form-check-label" for="pie-save-new">کپی جدید (پیشنهادی)</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="pie-save-mode" id="pie-save-replace" value="replace">
                <label class="form-check-label" for="pie-save-replace">جایگزینی تصویر فعلی</label>
              </div>
              <small class="text-muted d-block mt-1">کپی جدید تصویر ویرایش‌شده را جداگانه اضافه می‌کند؛ جایگزینی همان فایل را عوض می‌کند.</small>
            </div>
            <p id="pie-status" class="small text-muted mb-0"></p>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
        <button type="button" class="btn btn-success" id="pie-save-btn"><i class="bi bi-check-lg"></i> ذخیره</button>
      </div>
    </div>
  </div>
</div>`);
        bindOnce();
    }

    function bindOnce() {
        const $m = $('#productImageEditorModal');
        if ($m.data('pie-bound')) return;
        $m.data('pie-bound', true);

        $('#pie-rot-m90').on('click', () => {
            if (!state) return;
            setRotation(state.rotation - 90);
        });
        $('#pie-rot-p90').on('click', () => {
            if (!state) return;
            setRotation(state.rotation + 90);
        });
        $('#pie-flip-h').on('click', () => {
            if (!state) return;
            state.flipH = !state.flipH;
            clearRectCrop();
            redraw();
        });
        $('#pie-flip-v').on('click', () => {
            if (!state) return;
            state.flipV = !state.flipV;
            clearRectCrop();
            redraw();
        });
        $('#pie-rot-range').on('input', function () {
            if (!state) return;
            setRotation(parseInt(this.value, 10) || 0);
        });

        $('.pie-crop-mode').on('click', function () {
            if (!state) return;
            setCropMode($(this).data('mode'));
        });
        $('.pie-aspect').on('click', function () {
            if (!state) return;
            state.aspect = $(this).data('aspect');
            $('.pie-aspect').removeClass('btn-success').addClass('btn-outline-secondary');
            $(this).removeClass('btn-outline-secondary').addClass('btn-success');
            initRectCrop();
            updateRectCropEl();
        });
        $('#pie-clear-crop').on('click', () => {
            if (!state) return;
            clearRectCrop();
            $('#pie-hint').text(
                'برای کراپ، یکی از نسبت‌ها را انتخاب کنید. در غیر این صورت کل تصویر ذخیره می‌شود.'
            );
        });
        $('#pie-apply-persp').on('click', () => applyPerspective());

        $('.pie-bg').on('click', function () {
            if (!state) return;
            state.bgChoice = $(this).data('bg');
            $('.pie-bg').removeClass('btn-success').addClass('btn-outline-secondary');
            $(this).removeClass('btn-outline-secondary').addClass('btn-success');
            $('#pie-bg-color').toggle(state.bgChoice === 'custom');
        });

        $('#pie-rembg-now').on('click', () => removeBgNow());
        $('#pie-save-btn').on('click', () => save());

        $('#pie-rect-crop').on('pointerdown', function (e) {
            if (!state || state.cropMode !== 'rect' || !state.rectCrop) return;
            e.preventDefault();
            e.stopPropagation();

            const scale = displayScale();
            const rect = this.getBoundingClientRect();
            const localX = e.clientX - rect.left;
            const localY = e.clientY - rect.top;
            const w = rect.width;
            const h = rect.height;

            let corner = $(e.target).closest('.pie-crop-handle').data('corner') || null;
            if (!corner) {
                const nearL = localX <= HANDLE_HIT;
                const nearR = localX >= w - HANDLE_HIT;
                const nearT = localY <= HANDLE_HIT;
                const nearB = localY >= h - HANDLE_HIT;
                if (nearL && nearT) corner = 'nw';
                else if (nearR && nearT) corner = 'ne';
                else if (nearR && nearB) corner = 'se';
                else if (nearL && nearB) corner = 'sw';
            }

            const startX = e.clientX;
            const startY = e.clientY;
            const orig = { ...state.rectCrop };
            const aspect = ASPECTS.find((a) => a.value === state.aspect)?.ratio || null;
            const pointerId = e.pointerId;
            this.setPointerCapture?.(pointerId);

            const onMove = (ev) => {
                if (!state || ev.pointerId !== pointerId) return;
                const dx = (ev.clientX - startX) / scale;
                const dy = (ev.clientY - startY) / scale;
                const maxW = state.displayWidth;
                const maxH = state.displayHeight;

                if (!corner) {
                    state.rectCrop.x = clamp(orig.x + dx, 0, maxW - orig.w);
                    state.rectCrop.y = clamp(orig.y + dy, 0, maxH - orig.h);
                } else {
                    let x = orig.x;
                    let y = orig.y;
                    let cw = orig.w;
                    let ch = orig.h;

                    if (corner.includes('e')) cw = orig.w + dx;
                    if (corner.includes('s')) ch = orig.h + dy;
                    if (corner.includes('w')) {
                        cw = orig.w - dx;
                        x = orig.x + dx;
                    }
                    if (corner.includes('n')) {
                        ch = orig.h - dy;
                        y = orig.y + dy;
                    }

                    const minSize = 20;
                    if (aspect) {
                        if (corner === 'ne' || corner === 'sw') {
                            if (Math.abs(dx) * aspect > Math.abs(dy)) ch = cw / aspect;
                            else cw = ch * aspect;
                        } else {
                            if (Math.abs(dx) > Math.abs(dy) * aspect) ch = cw / aspect;
                            else cw = ch * aspect;
                        }
                        if (corner.includes('w')) x = orig.x + orig.w - cw;
                        if (corner.includes('n')) y = orig.y + orig.h - ch;
                    }

                    if (cw < minSize) {
                        if (corner.includes('w')) x = orig.x + orig.w - minSize;
                        cw = minSize;
                        if (aspect) {
                            ch = cw / aspect;
                            if (corner.includes('n')) y = orig.y + orig.h - ch;
                        }
                    }
                    if (ch < minSize) {
                        if (corner.includes('n')) y = orig.y + orig.h - minSize;
                        ch = minSize;
                        if (aspect) {
                            cw = ch * aspect;
                            if (corner.includes('w')) x = orig.x + orig.w - cw;
                        }
                    }

                    if (x < 0) {
                        cw += x;
                        x = 0;
                        if (aspect) ch = cw / aspect;
                    }
                    if (y < 0) {
                        ch += y;
                        y = 0;
                        if (aspect) cw = ch * aspect;
                    }
                    if (x + cw > maxW) {
                        cw = maxW - x;
                        if (aspect) ch = cw / aspect;
                    }
                    if (y + ch > maxH) {
                        ch = maxH - y;
                        if (aspect) cw = ch * aspect;
                    }

                    state.rectCrop = { x, y, w: cw, h: ch };
                }
                updateRectCropEl();
            };

            const onUp = (ev) => {
                if (ev.pointerId !== pointerId) return;
                this.releasePointerCapture?.(pointerId);
                $(window).off('pointermove', onMove).off('pointerup pointercancel', onUp);
            };
            $(window).on('pointermove', onMove).on('pointerup pointercancel', onUp);
        });

        $(window).on('resize.pie', () => {
            if (state) {
                updateRectCropEl();
                updatePerspHandles();
            }
        });

        $m.on('hidden.bs.modal', () => {
            // اگر تصویر بعدی صف همین الان state جدید ساخته، آن را پاک نکن
            if (!state) {
                closingSessionId = null;
                return;
            }
            if (closingSessionId != null && state.sessionId !== closingSessionId) {
                closingSessionId = null;
                return;
            }
            revokeBlobs();
            if (!state.saved && typeof state.onCancel === 'function') {
                try {
                    state.onCancel();
                } catch (_) {}
            }
            state = null;
            closingSessionId = null;
        });
    }

    function clamp(n, a, b) {
        return Math.max(a, Math.min(b, n));
    }

    function waitModalHidden(modalEl) {
        return new Promise((resolve) => {
            if (!modalEl || !modalEl.classList.contains('show')) {
                resolve();
                return;
            }
            const onHidden = () => resolve();
            modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });
            // safety: اگر رویداد نیاید، گیر نمانیم
            setTimeout(() => {
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                resolve();
            }, 800);
        });
    }

    function hideEditorModal() {
        const modalEl = document.getElementById('productImageEditorModal');
        if (!modalEl) return Promise.resolve();
        const inst = bootstrap.Modal.getInstance(modalEl);
        if (!inst || !modalEl.classList.contains('show')) return Promise.resolve();
        closingSessionId = state?.sessionId ?? closingSessionId;
        const done = waitModalHidden(modalEl);
        inst.hide();
        return done;
    }

    function makeUploadFile(blob, name, fallbackType) {
        if (!blob) throw new Error('تبدیل تصویر برای آپلود ناموفق بود');
        const type = blob.type || fallbackType || 'application/octet-stream';
        return new File([blob], name, { type });
    }

    /**
     * کاهش حجم قبل از آپلود به rembg (جلوگیری از خطای محدودیت حجم سرور)
     */
    async function prepareUploadCanvas(srcCanvas, maxEdge = 2400) {
        const w = srcCanvas.width;
        const h = srcCanvas.height;
        const longest = Math.max(w, h);
        if (longest <= maxEdge) return srcCanvas;
        const scale = maxEdge / longest;
        const out = document.createElement('canvas');
        out.width = Math.max(1, Math.round(w * scale));
        out.height = Math.max(1, Math.round(h * scale));
        const ctx = out.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(srcCanvas, 0, 0, out.width, out.height);
        return out;
    }

    async function canvasToUploadFile(srcCanvas, { preferPng = false, maxEdge = 2400 } = {}) {
        const canvas = await prepareUploadCanvas(srcCanvas, maxEdge);
        if (preferPng) {
            const blob = await canvasToBlob(canvas, 'image/png');
            if (blob.size > 4 * 1024 * 1024) {
                const jpg = await canvasToBlob(canvas, 'image/jpeg', 0.9);
                return makeUploadFile(jpg, 'edit.jpg', 'image/jpeg');
            }
            return makeUploadFile(blob, 'edit.png', 'image/png');
        }
        const blob = await canvasToBlob(canvas, 'image/jpeg', 0.9);
        return makeUploadFile(blob, 'edit.jpg', 'image/jpeg');
    }

    function setBusy(on) {
        $('#pie-busy').toggleClass('d-none', !on).toggleClass('d-flex', on);
        $('#pie-save-btn, #pie-rembg-now').prop('disabled', on);
    }

    function setStatus(msg) {
        $('#pie-status').text(msg || '');
    }

    function setQueueTitle(index, total) {
        if (total > 1) {
            $('#pie-modal-title').text(`ویرایش تصویر (${index} از ${total})`);
        } else {
            $('#pie-modal-title').text('ویرایش تصویر');
        }
    }

    function setRotation(deg) {
        if (!state) return;
        state.rotation = deg;
        let shown = ((deg + 180) % 360) - 180;
        if (shown === -180 && deg > 0) shown = 180;
        $('#pie-rot-range').val(shown);
        $('#pie-rot-label').text(Math.round(deg) + '°');
        clearRectCrop();
        redraw();
    }

    function setCropMode(mode) {
        if (!state) return;
        state.cropMode = mode;
        $('.pie-crop-mode').removeClass('btn-success').addClass('btn-outline-secondary');
        $(`.pie-crop-mode[data-mode="${mode}"]`).removeClass('btn-outline-secondary').addClass('btn-success');
        $('#pie-aspect-row, #pie-clear-crop').toggle(mode === 'rect');
        $('#pie-apply-persp').toggle(mode === 'perspective');
        $('#pie-rect-crop').toggle(mode === 'rect' && !!state.rectCrop);
        $('#pie-persp-svg, #pie-persp-handles').toggle(mode === 'perspective');
        $('#pie-hint').text(
            mode === 'perspective'
                ? 'چهار گوشه را روی لبه‌های سوژه بکشید، سپس اعمال پرسپکتیو یا ذخیره را بزنید.'
                : state.rectCrop
                  ? 'کادر کراپ را جابه‌جا کنید یا از گوشه‌ها تغییر اندازه دهید.'
                  : 'برای کراپ، یکی از نسبت‌ها را انتخاب کنید. در غیر این صورت کل تصویر ذخیره می‌شود.'
        );
        if (mode === 'perspective' && !state.persp) {
            initPersp();
        }
        updateRectCropEl();
        updatePerspHandles();
    }

    function revokeBlobs() {
        (state?.blobUrls || []).forEach((u) => {
            try {
                URL.revokeObjectURL(u);
            } catch (_) {}
        });
        if (state) state.blobUrls = [];
    }

    function loadImageFromSrc(src) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => resolve(img);
            img.onerror = () => reject(new Error('بارگذاری تصویر ناموفق بود'));
            img.src = src;
        });
    }

    async function open(opts) {
        ensureModal();

        const modalEl = document.getElementById('productImageEditorModal');
        // اگر مودال هنوز در حال بسته شدن است، صبر کن تا state نشست قبلی پاک شود
        if (modalEl?.classList.contains('show')) {
            await hideEditorModal();
        } else if (state) {
            revokeBlobs();
            state = null;
        }

        let src = opts.src || '';
        if (opts.file) {
            src = URL.createObjectURL(opts.file);
        }

        const sessionId = ++sessionSeq;
        state = {
            sessionId,
            productId: opts.productId || null,
            imageId: opts.imageId || null,
            onSave: opts.onSave || null,
            onCancel: opts.onCancel || null,
            mode: opts.mode || 'return', // return | upload | replace | edit
            allowSaveMode: !!(opts.imageId && opts.productId) || opts.mode === 'edit',
            originalSrc: src,
            sourceImg: null,
            width: 0,
            height: 0,
            displayWidth: 0,
            displayHeight: 0,
            rotation: 0,
            flipH: false,
            flipV: false,
            cropMode: 'rect',
            aspect: 'free',
            rectCrop: null,
            persp: null,
            bgChoice: 'white',
            blobUrls: opts.file ? [src] : [],
            saved: false,
            queueIndex: opts.queueIndex || null,
            queueTotal: opts.queueTotal || null,
        };

        setQueueTitle(state.queueIndex || 1, state.queueTotal || 1);
        $('#pie-model').val('isnet-general-use');
        $('#pie-rembg-onsave').prop('checked', false);
        $('#pie-rot-range').val(0);
        $('#pie-rot-label').text('0°');
        $('.pie-bg').removeClass('btn-success').addClass('btn-outline-secondary');
        $('.pie-bg[data-bg="white"]').removeClass('btn-outline-secondary').addClass('btn-success');
        $('#pie-bg-color').hide().val('#ffffff');
        $('.pie-aspect').removeClass('btn-success').addClass('btn-outline-secondary');
        $('.pie-aspect[data-aspect="free"]').removeClass('btn-outline-secondary').addClass('btn-success');
        if (state.allowSaveMode) {
            $('#pie-save-mode-box').show();
            $('#pie-save-new').prop('checked', true);
            $('#pie-save-replace').prop('checked', false);
        } else {
            $('#pie-save-mode-box').hide();
        }
        setStatus(opts.statusHint || '');
        setBusy(true);

        try {
            const img = await loadImageFromSrc(src);
            if (!state || state.sessionId !== sessionId) return null;
            state.sourceImg = img;
            state.width = img.naturalWidth;
            state.height = img.naturalHeight;
            state.rectCrop = null;
            updateDisplaySize();
            redraw();
            setCropMode('rect');
            checkRembg();
        } catch (e) {
            setStatus(e.message || 'خطا');
            if (typeof UI !== 'undefined') UI.showError(e.message || 'بارگذاری تصویر ناموفق بود');
        } finally {
            setBusy(false);
        }

        if (!state || state.sessionId !== sessionId) return null;

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        return new Promise((resolve) => {
            let settled = false;
            const finish = (value) => {
                if (settled) return;
                settled = true;
                resolve(value);
            };
            if (state) state._resolve = finish;
            $(modalEl).one('hidden.bs.modal.pieopen', () => {
                // فقط اگر ذخیره نشده باشد (انصراف / بستن)
                if (!settled) finish(null);
            });
        });
    }

    function rotatedSize(w, h, angleDeg) {
        const angle = ((Math.round(angleDeg) % 360) + 360) % 360;
        if (!angle) return { w, h };
        const rad = (angle * Math.PI) / 180;
        const sin = Math.abs(Math.sin(rad));
        const cos = Math.abs(Math.cos(rad));
        return {
            w: Math.round(w * cos + h * sin) || w,
            h: Math.round(w * sin + h * cos) || h,
        };
    }

    function updateDisplaySize() {
        if (!state) return;
        const size = rotatedSize(state.width, state.height, state.rotation);
        state.displayWidth = size.w;
        state.displayHeight = size.h;
    }

    function clearRectCrop() {
        if (!state) return;
        updateDisplaySize();
        state.rectCrop = null;
        updateRectCropEl();
    }

    function initRectCrop() {
        if (!state) return;
        updateDisplaySize();
        const aspect = ASPECTS.find((a) => a.value === state.aspect)?.ratio;
        const w = state.displayWidth;
        const h = state.displayHeight;
        let cw = w * 0.9;
        let ch = h * 0.9;
        if (aspect) {
            if (cw / ch > aspect) cw = ch * aspect;
            else ch = cw / aspect;
        }
        state.rectCrop = {
            x: (w - cw) / 2,
            y: (h - ch) / 2,
            w: cw,
            h: ch,
        };
        $('#pie-hint').text('کادر کراپ را جابه‌جا کنید یا از گوشه‌ها تغییر اندازه دهید.');
    }

    function initPersp() {
        const inset = 0.08;
        const w = state.displayWidth || state.width;
        const h = state.displayHeight || state.height;
        state.persp = [
            { x: w * inset, y: h * inset },
            { x: w * (1 - inset), y: h * inset },
            { x: w * (1 - inset), y: h * (1 - inset) },
            { x: w * inset, y: h * (1 - inset) },
        ];
    }

    function displayScale() {
        const canvas = document.getElementById('pie-canvas');
        if (!canvas || !state?.displayWidth) return 1;
        return canvas.clientWidth / state.displayWidth;
    }

    function redraw() {
        if (!state?.sourceImg) return;
        const canvas = document.getElementById('pie-canvas');
        const ctx = canvas.getContext('2d');
        const upright = getUprightCanvas();
        const transformed = applyRotateFlip(upright);
        state.displayWidth = transformed.width;
        state.displayHeight = transformed.height;
        canvas.width = transformed.width;
        canvas.height = transformed.height;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(transformed, 0, 0);
        updateRectCropEl();
        updatePerspHandles();
    }

    function getUprightCanvas() {
        const c = document.createElement('canvas');
        c.width = state.width;
        c.height = state.height;
        c.getContext('2d').drawImage(state.sourceImg, 0, 0, state.width, state.height);
        return c;
    }

    function applyRotateFlip(srcCanvas) {
        const angle = ((Math.round(state.rotation) % 360) + 360) % 360;
        if (!angle && !state.flipH && !state.flipV) return srcCanvas;

        const rad = (angle * Math.PI) / 180;
        const sin = Math.abs(Math.sin(rad));
        const cos = Math.abs(Math.cos(rad));
        const w = srcCanvas.width;
        const h = srcCanvas.height;
        const nw = Math.round(w * cos + h * sin) || w;
        const nh = Math.round(w * sin + h * cos) || h;
        const out = document.createElement('canvas');
        out.width = nw;
        out.height = nh;
        const ctx = out.getContext('2d');
        ctx.translate(nw / 2, nh / 2);
        ctx.rotate(rad);
        ctx.scale(state.flipH ? -1 : 1, state.flipV ? -1 : 1);
        ctx.drawImage(srcCanvas, -w / 2, -h / 2);
        return out;
    }

    function updateRectCropEl() {
        const $el = $('#pie-rect-crop');
        if (!state || state.cropMode !== 'rect' || !state.rectCrop) {
            $el.hide();
            return;
        }
        const s = displayScale();
        const c = state.rectCrop;
        $el.css({
            display: 'block',
            left: c.x * s + 'px',
            top: c.y * s + 'px',
            width: c.w * s + 'px',
            height: c.h * s + 'px',
        }).show();
    }

    function updatePerspHandles() {
        if (!state || state.cropMode !== 'perspective' || !state.persp) {
            $('#pie-persp-svg, #pie-persp-handles').hide();
            return;
        }
        const s = displayScale();
        const pts = state.persp.map((p) => ({ x: p.x * s, y: p.y * s }));
        const poly = pts.map((p) => `${p.x},${p.y}`).join(' ');
        $('#pie-persp-svg')
            .html(
                `<polygon points="${poly}" fill="rgba(16,185,129,0.12)" stroke="#34d399" stroke-width="2" stroke-dasharray="6 4"/>`
            )
            .show();

        const $h = $('#pie-persp-handles').empty().show();
        const labels = ['↖', '↗', '↘', '↙'];
        pts.forEach((p, i) => {
            const $btn = $(
                `<button type="button" class="btn btn-sm btn-success rounded-circle position-absolute pie-persp-handle" style="width:28px;height:28px;padding:0;z-index:5;transform:translate(-50%,-50%);left:${p.x}px;top:${p.y}px;touch-action:none;">${labels[i]}</button>`
            );
            $btn.on('pointerdown', function (e) {
                e.preventDefault();
                e.stopPropagation();
                dragCorner = i;
                const pointerId = e.pointerId;
                this.setPointerCapture?.(pointerId);
                const onMove = (ev) => {
                    if (!state || ev.pointerId !== pointerId) return;
                    const scale = displayScale();
                    const wrap = document.getElementById('pie-canvas-wrap').getBoundingClientRect();
                    const x = clamp((ev.clientX - wrap.left) / scale, 0, state.displayWidth);
                    const y = clamp((ev.clientY - wrap.top) / scale, 0, state.displayHeight);
                    state.persp[dragCorner] = { x, y };
                    updatePerspHandles();
                };
                const onUp = (ev) => {
                    if (ev.pointerId !== pointerId) return;
                    dragCorner = null;
                    this.releasePointerCapture?.(pointerId);
                    $(window).off('pointermove', onMove).off('pointerup pointercancel', onUp);
                };
                $(window).on('pointermove', onMove).on('pointerup pointercancel', onUp);
            });
            $h.append($btn);
        });
    }

    function dist(a, b) {
        return Math.hypot(a.x - b.x, a.y - b.y);
    }

    function perspectiveCoeffs(corners) {
        const xs = corners.map((c) => c.x);
        const ys = corners.map((c) => c.y);
        const us = [0, 1, 1, 0];
        const vs = [0, 0, 1, 1];
        const A = [];
        const B = [];
        for (let i = 0; i < 4; i++) {
            const u = us[i],
                v = vs[i],
                x = xs[i],
                y = ys[i];
            A.push([u, v, 1, 0, 0, 0, -u * x, -v * x]);
            B.push(x);
            A.push([0, 0, 0, u, v, 1, -u * y, -v * y]);
            B.push(y);
        }
        return solveLinear8(A, B);
    }

    function solveLinear8(A, B) {
        const n = 8;
        const M = A.map((row, i) => [...row, B[i]]);
        for (let col = 0; col < n; col++) {
            let pivot = col;
            for (let r = col + 1; r < n; r++) {
                if (Math.abs(M[r][col]) > Math.abs(M[pivot][col])) pivot = r;
            }
            if (Math.abs(M[pivot][col]) < 1e-12) throw new Error('پرسپکتیو نامعتبر است');
            if (pivot !== col) {
                const t = M[col];
                M[col] = M[pivot];
                M[pivot] = t;
            }
            const div = M[col][col];
            for (let c = col; c <= n; c++) M[col][c] /= div;
            for (let r = 0; r < n; r++) {
                if (r === col) continue;
                const f = M[r][col];
                for (let c = col; c <= n; c++) M[r][c] -= f * M[col][c];
            }
        }
        return M.map((row) => row[n]);
    }

    function mapUV(coeffs, u, v) {
        const [a, b, c, d, e, f, g, h] = coeffs;
        const den = g * u + h * v + 1;
        return { x: (a * u + b * v + c) / den, y: (d * u + e * v + f) / den };
    }

    function sampleBilinear(data, w, h, x, y) {
        if (x < 0 || y < 0 || x >= w - 1 || y >= h - 1) {
            const xi = clamp(Math.round(x), 0, w - 1);
            const yi = clamp(Math.round(y), 0, h - 1);
            const i = (yi * w + xi) * 4;
            return [data[i], data[i + 1], data[i + 2], data[i + 3]];
        }
        const x0 = Math.floor(x),
            y0 = Math.floor(y);
        const x1 = x0 + 1,
            y1 = y0 + 1;
        const dx = x - x0,
            dy = y - y0;
        const out = [0, 0, 0, 0];
        for (let c = 0; c < 4; c++) {
            const v00 = data[(y0 * w + x0) * 4 + c];
            const v10 = data[(y0 * w + x1) * 4 + c];
            const v01 = data[(y1 * w + x0) * 4 + c];
            const v11 = data[(y1 * w + x1) * 4 + c];
            out[c] = Math.round(
                v00 * (1 - dx) * (1 - dy) +
                    v10 * dx * (1 - dy) +
                    v01 * (1 - dx) * dy +
                    v11 * dx * dy
            );
        }
        return out;
    }

    function warpPerspective(srcCanvas, corners) {
        const [tl, tr, br, bl] = corners;
        const width = Math.max(1, Math.round((dist(tl, tr) + dist(bl, br)) / 2));
        const height = Math.max(1, Math.round((dist(tl, bl) + dist(tr, br)) / 2));
        const sctx = srcCanvas.getContext('2d', { willReadFrequently: true });
        const srcData = sctx.getImageData(0, 0, srcCanvas.width, srcCanvas.height).data;
        const coeffs = perspectiveCoeffs(corners);
        const out = document.createElement('canvas');
        out.width = width;
        out.height = height;
        const octx = out.getContext('2d');
        const imageData = octx.createImageData(width, height);
        const dst = imageData.data;
        const sw = srcCanvas.width,
            sh = srcCanvas.height;
        for (let y = 0; y < height; y++) {
            const v = height <= 1 ? 0 : y / (height - 1);
            for (let x = 0; x < width; x++) {
                const u = width <= 1 ? 0 : x / (width - 1);
                const p = mapUV(coeffs, u, v);
                const [r, g, b, a] = sampleBilinear(srcData, sw, sh, p.x, p.y);
                const i = (y * width + x) * 4;
                dst[i] = r;
                dst[i + 1] = g;
                dst[i + 2] = b;
                dst[i + 3] = a;
            }
        }
        octx.putImageData(imageData, 0, 0);
        return out;
    }

    async function applyPerspective() {
        if (!state?.persp) return;
        const sessionId = state.sessionId;
        setBusy(true);
        try {
            const base = applyRotateFlip(getUprightCanvas());
            const out = warpPerspective(base, state.persp);
            const blob = await canvasToBlob(out, 'image/png');
            const url = URL.createObjectURL(blob);
            if (!state || state.sessionId !== sessionId) return;
            state.blobUrls.push(url);
            const img = await loadImageFromSrc(url);
            if (!state || state.sessionId !== sessionId) return;
            state.sourceImg = img;
            state.width = img.naturalWidth;
            state.height = img.naturalHeight;
            state.rotation = 0;
            state.flipH = false;
            state.flipV = false;
            state.persp = null;
            $('#pie-rot-range').val(0);
            $('#pie-rot-label').text('0°');
            clearRectCrop();
            redraw();
            setCropMode('rect');
            setStatus('کراپ پرسپکتیو اعمال شد.');
        } catch (e) {
            setStatus(e.message || 'خطا در پرسپکتیو');
        } finally {
            setBusy(false);
        }
    }

    function canvasToBlob(canvas, type, quality) {
        return new Promise((resolve, reject) => {
            canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('تبدیل ناموفق'))), type, quality);
        });
    }

    function bgValue() {
        if (state.bgChoice === 'transparent') return 'transparent';
        if (state.bgChoice === 'white') return '#ffffff';
        return $('#pie-bg-color').val() || '#ffffff';
    }

    async function buildOutputCanvas() {
        // ترتیب با پیش‌نمایش یکی است: چرخش/قرینه → کراپ
        let canvas = applyRotateFlip(getUprightCanvas());

        if (state.cropMode === 'perspective' && state.persp) {
            canvas = warpPerspective(canvas, state.persp);
        } else if (state.cropMode === 'rect' && state.rectCrop) {
            const c = state.rectCrop;
            const nearlyFull =
                c.x <= 1 &&
                c.y <= 1 &&
                c.w >= state.displayWidth - 2 &&
                c.h >= state.displayHeight - 2;
            if (!nearlyFull) {
                const cropped = document.createElement('canvas');
                cropped.width = Math.max(1, Math.round(c.w));
                cropped.height = Math.max(1, Math.round(c.h));
                cropped
                    .getContext('2d')
                    .drawImage(
                        canvas,
                        Math.round(c.x),
                        Math.round(c.y),
                        Math.round(c.w),
                        Math.round(c.h),
                        0,
                        0,
                        cropped.width,
                        cropped.height
                    );
                canvas = cropped;
            }
        }

        const bg = bgValue();
        if (bg !== 'transparent') {
            const flat = document.createElement('canvas');
            flat.width = canvas.width;
            flat.height = canvas.height;
            const ctx = flat.getContext('2d');
            ctx.fillStyle = bg;
            ctx.fillRect(0, 0, flat.width, flat.height);
            ctx.drawImage(canvas, 0, 0);
            canvas = flat;
        }
        return canvas;
    }

    async function checkRembg() {
        try {
            const r = await Api.call('product_image_rembg_status', {});
            if (r && r.available === false) {
                $('#pie-rembg-status').text('سرویس rembg در دسترس نیست.');
                $('#pie-rembg-now, #pie-rembg-onsave').prop('disabled', true);
            } else {
                $('#pie-rembg-status').text('');
                $('#pie-rembg-now, #pie-rembg-onsave').prop('disabled', false);
            }
        } catch (_) {
            // اگر دسترسی نبود، ابزار را فعال نگه دار تا خود process خطا بدهد
            $('#pie-rembg-status').text('');
            $('#pie-rembg-now, #pie-rembg-onsave').prop('disabled', false);
        }
    }

    async function removeBgNow() {
        if (!state) return;
        const sessionId = state.sessionId;
        setBusy(true);
        setStatus('در حال حذف پس‌زمینه...');
        try {
            const canvas = await buildOutputCanvas();
            const file = await canvasToUploadFile(canvas, { preferPng: false, maxEdge: 2400 });
            const fd = new FormData();
            fd.append('image', file);
            fd.append('remove_bg', '1');
            fd.append('model', $('#pie-model').val());
            fd.append('background', 'transparent');
            const result = await Api.uploadFile('process_product_image', fd);
            if (!result?.success) {
                setStatus(result?.error || 'حذف پس‌زمینه ناموفق بود');
                return;
            }
            if (!state || state.sessionId !== sessionId) return;
            const url = result.file_path + '?t=' + Date.now();
            const img = await loadImageFromSrc(url);
            if (!state || state.sessionId !== sessionId) return;
            state.sourceImg = img;
            state.width = img.naturalWidth;
            state.height = img.naturalHeight;
            state.rotation = 0;
            state.flipH = false;
            state.flipV = false;
            state.persp = null;
            $('#pie-rot-range').val(0);
            $('#pie-rot-label').text('0°');
            clearRectCrop();
            redraw();
            setCropMode('rect');
            setStatus('پس‌زمینه حذف شد.');
            if (typeof UI !== 'undefined') UI.showSuccess('پس‌زمینه حذف شد');
        } catch (e) {
            setStatus(e.message || 'خطا');
        } finally {
            setBusy(false);
        }
    }

    async function save() {
        if (!state) return;
        const sessionId = state.sessionId;
        setBusy(true);
        setStatus('در حال ذخیره...');
        try {
            if ($('#pie-rembg-onsave').is(':checked')) {
                const canvas0 = await buildOutputCanvas();
                const file0 = await canvasToUploadFile(canvas0, { preferPng: false, maxEdge: 2400 });
                const fd0 = new FormData();
                fd0.append('image', file0);
                fd0.append('remove_bg', '1');
                fd0.append('model', $('#pie-model').val());
                fd0.append('background', bgValue());
                const r0 = await Api.uploadFile('process_product_image', fd0);
                if (!r0?.success) {
                    setStatus(r0?.error || 'حذف پس‌زمینه ناموفق بود');
                    return;
                }
                if (!state || state.sessionId !== sessionId) return;
                const img = await loadImageFromSrc(r0.file_path + '?t=' + Date.now());
                if (!state || state.sessionId !== sessionId) return;
                state.sourceImg = img;
                state.width = img.naturalWidth;
                state.height = img.naturalHeight;
                state.rotation = 0;
                state.flipH = false;
                state.flipV = false;
                state.persp = null;
                state.rectCrop = null;
                $('#pie-rot-range').val(0);
                $('#pie-rot-label').text('0°');
                redraw();
            }

            if (!state || state.sessionId !== sessionId) return;

            const canvas = await buildOutputCanvas();
            const bg = bgValue();
            const mime = bg === 'transparent' ? 'image/png' : 'image/jpeg';
            const blob = await canvasToBlob(canvas, mime, 0.92);
            const file = new File(
                [blob],
                `edited-${Date.now()}.${mime === 'image/png' ? 'png' : 'jpg'}`,
                { type: mime }
            );

            let effectiveMode = state.mode;
            if (state.allowSaveMode) {
                effectiveMode =
                    $('input[name="pie-save-mode"]:checked').val() === 'replace' ? 'replace' : 'new';
            }

            let successMsg = 'تصویر ذخیره شد';
            if (effectiveMode === 'replace' && state.imageId) {
                const fd = new FormData();
                fd.append('image', file);
                fd.append('image_id', state.imageId);
                const result = await Api.uploadFile('replace_product_image', fd);
                if (!result?.success) {
                    setStatus(result?.error || 'ذخیره ناموفق');
                    return;
                }
                successMsg = 'تصویر جایگزین شد';
            } else if (
                (effectiveMode === 'new' || effectiveMode === 'upload' || effectiveMode === 'edit') &&
                state.productId
            ) {
                const fd = new FormData();
                fd.append('image', file);
                fd.append('product_id', state.productId);
                const result = await Api.uploadFile('upload_product_image', fd);
                if (!result?.success) {
                    setStatus(result?.error || 'آپلود ناموفق');
                    return;
                }
                successMsg = state.allowSaveMode ? 'کپی جدید ذخیره شد' : 'تصویر ذخیره شد';
            }

            state.saved = true;
            const payload = { file, blob, mime, saveMode: effectiveMode };
            if (typeof state.onSave === 'function') {
                await state.onSave(payload);
            }
            // حتماً قبل از hide resolve کن؛ وگرنه hidden.bs.modal مقدار null برمی‌گرداند
            const resolve = state._resolve;
            state._resolve = null;
            if (resolve) resolve(payload);
            await hideEditorModal();
            if (typeof UI !== 'undefined') UI.showSuccess(successMsg);
        } catch (e) {
            setStatus(e.message || 'خطا در ذخیره');
        } finally {
            setBusy(false);
        }
    }

    /**
     * Edit multiple files sequentially; returns array of File results (skip cancelled).
     */
    async function openQueue(files, baseOpts) {
        const out = [];
        const list = Array.from(files || []);
        for (let i = 0; i < list.length; i++) {
            const result = await open({
                ...baseOpts,
                file: list[i],
                mode: baseOpts?.mode || 'return',
                queueIndex: i + 1,
                queueTotal: list.length,
                statusHint: list.length > 1 ? `تصویر ${i + 1} از ${list.length}` : '',
            });
            if (result?.file) out.push(result.file);
            // مطمئن شو مودال کاملاً بسته شده قبل از تصویر بعدی
            await hideEditorModal();
        }
        $('#pie-modal-title').text('ویرایش تصویر');
        return out;
    }

    return { open, openQueue, waitModalHidden, hideEditorModal };
})(jQuery);
