// /js/stock-share-manager.js
// لینک موجودی برای همکار: create, edit, copy, send via WhatsApp and revoke
// public live stock links (stock.php?c=...). Opened from the price list page.

const StockShareManager = (function () {
    let state = { links: [], options: { warehouses: [], collections: [], sizes: [] }, defaultPct: 0 };
    let initialized = false;

    const PRICE_MODE_LABELS = { sale: 'قیمت فروش', partner: 'قیمت همکاری', none: 'بدون قیمت' };

    function esc(v) {
        return UI.escapeHtml(String(v ?? ''));
    }

    function faNumber(n) {
        return Number(n || 0).toLocaleString('fa-IR');
    }

    function faDateTime(value) {
        if (!value) return '—';
        const d = new Date(String(value).replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(value);
        return d.toLocaleDateString('fa-IR') + ' ' + d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    }

    function chipHtml(group, index, value, label, checked) {
        const id = `stock-share-${group}-${index}`;
        return `<input type="checkbox" class="btn-check stock-share-chip" data-group="${group}" id="${esc(id)}" value="${esc(value)}" ${checked ? 'checked' : ''}>
            <label class="btn btn-sm btn-outline-secondary" for="${esc(id)}">${esc(label)}</label>`;
    }

    function renderScope(filters = {}) {
        const f = Object.assign({ warehouse_ids: [], collection_ids: [], include_singles: false, sizes: [] }, filters);
        const { warehouses, collections, sizes } = state.options;

        $('#stock-share-warehouses').html(warehouses.length
            ? warehouses.map((w, i) => chipHtml('warehouse', i, w.id, w.name, f.warehouse_ids.includes(Number(w.id)))).join('')
            : '<span class="text-muted small">انباری تعریف نشده است.</span>');

        $('#stock-share-collections').html(
            collections.map((c, i) => chipHtml('collection', i, c.id, c.name, f.collection_ids.includes(Number(c.id)))).join('')
            + chipHtml('singles', 0, '1', 'محصولات تکی (بدون کلکسیون)', !!f.include_singles)
        );

        $('#stock-share-sizes').html(sizes.length
            ? sizes.map((s, i) => chipHtml('size', i, s, s.replace(/x/gi, '×'), f.sizes.includes(s))).join('')
            : '<span class="text-muted small">کالای موجودی در انبار نیست.</span>');
    }

    function checkedValues(group) {
        return $(`.stock-share-chip[data-group="${group}"]:checked`).map(function () { return $(this).val(); }).get();
    }

    function priceMode() {
        return $('input[name="stock_share_price_mode"]:checked').val() || 'sale';
    }

    function syncModeUi() {
        $('#stock-share-pct-wrap').toggle(priceMode() === 'partner');
    }

    function resetForm() {
        $('#stock-share-id').val('');
        $('#stock-share-title').val('');
        $('#stock-share-mode-sale').prop('checked', true);
        $('#stock-share-pct').val(state.defaultPct || '');
        $('#stock-share-show-serials').prop('checked', true);
        $('#stock-share-show-counts').prop('checked', true);
        $('#stock-share-save').html('<i class="bi bi-link-45deg me-1"></i>ساخت لینک');
        $('#stock-share-cancel-edit').addClass('d-none');
        renderScope();
        syncModeUi();
    }

    function editLink(id) {
        const link = state.links.find(l => Number(l.id) === Number(id));
        if (!link) return;
        $('#stock-share-id').val(link.id);
        $('#stock-share-title').val(link.title);
        $(`#stock-share-mode-${link.price_mode}`).prop('checked', true);
        $('#stock-share-pct').val(Number(link.partner_discount_pct) || '');
        $('#stock-share-show-serials').prop('checked', Number(link.show_serials) === 1);
        $('#stock-share-show-counts').prop('checked', Number(link.show_counts) === 1);
        $('#stock-share-save').html('<i class="bi bi-check-lg me-1"></i>ذخیرهٔ تغییرات (آدرس لینک ثابت می‌ماند)');
        $('#stock-share-cancel-edit').removeClass('d-none');
        renderScope(link.filters);
        syncModeUi();
        $('#stock-share-title').trigger('focus');
    }

    function scopeSummary(f) {
        const parts = [];
        if (f.warehouse_ids.length) parts.push(`${faNumber(f.warehouse_ids.length)} انبار`);
        if (f.collection_ids.length) parts.push(`${faNumber(f.collection_ids.length)} کلکسیون`);
        if (f.include_singles) parts.push('محصولات تکی');
        if (f.sizes.length) parts.push(`${faNumber(f.sizes.length)} سایز`);
        return parts.length ? parts.join('، ') : 'همهٔ موجودی';
    }

    function renderLinks() {
        const $box = $('#stock-share-links');
        if (!state.links.length) {
            $box.html('<p class="text-muted small mb-0">هنوز لینکی ساخته نشده است.</p>');
            return;
        }
        const rows = state.links.map(l => {
            const revoked = !!l.revoked_at;
            const price = l.price_mode === 'partner'
                ? `${PRICE_MODE_LABELS.partner} (${faNumber(l.partner_discount_pct)}٪)`
                : PRICE_MODE_LABELS[l.price_mode] || '';
            const actions = revoked
                ? `<span class="badge bg-secondary">باطل‌شده ${faDateTime(l.revoked_at)}</span>`
                : `<div class="d-flex flex-wrap gap-1 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-primary stock-share-copy" data-id="${l.id}" title="کپی لینک"><i class="bi bi-clipboard"></i> کپی</button>
                    <button type="button" class="btn btn-sm btn-outline-success stock-share-wa" data-id="${l.id}" title="ارسال در واتس‌اپ"><i class="bi bi-whatsapp"></i> واتس‌اپ</button>
                    <a class="btn btn-sm btn-outline-secondary" href="${esc(l.url)}" target="_blank" rel="noopener" title="باز کردن لینک"><i class="bi bi-box-arrow-up-left"></i></a>
                    <button type="button" class="btn btn-sm btn-outline-secondary stock-share-edit" data-id="${l.id}" title="ویرایش"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger stock-share-revoke" data-id="${l.id}" title="باطل کردن"><i class="bi bi-x-circle"></i> باطل</button>
                </div>`;
            return `<tr class="${revoked ? 'text-muted' : ''}">
                <td class="text-end fw-bold">${esc(l.title)}</td>
                <td>${esc(price)}</td>
                <td>${esc(scopeSummary(l.filters))}</td>
                <td>${faDateTime(l.created_at)}</td>
                <td>${l.last_viewed_at ? `${faDateTime(l.last_viewed_at)} <small class="text-muted">(${faNumber(l.view_count)} بار)</small>` : '<span class="text-muted">باز نشده</span>'}</td>
                <td>${actions}</td>
            </tr>`;
        }).join('');
        $box.html(`<table class="table table-sm table-hover align-middle text-center mb-0">
            <thead class="table-light"><tr>
                <th class="text-end">نام</th><th>قیمت</th><th>محدوده</th><th>ساخت</th><th>آخرین بازدید</th><th></th>
            </tr></thead><tbody>${rows}</tbody></table>`);
    }

    async function load() {
        const data = await Api.call('get_stock_share_links');
        if (!data || data.error) return false;
        state.links = data.links || [];
        state.options = data.options || state.options;
        state.defaultPct = Number(data.default_partner_pct) || 0;
        renderLinks();
        return true;
    }

    async function open() {
        UI.showLoader();
        const ok = await load();
        UI.hideLoader();
        if (!ok) return;
        resetForm();
        $('#stockShareModal').modal('show');
    }

    async function save(e) {
        e?.preventDefault();
        const payload = {
            id: parseInt($('#stock-share-id').val(), 10) || 0,
            title: $('#stock-share-title').val().trim(),
            price_mode: priceMode(),
            partner_discount_pct: UI.toEnglishDigits(String($('#stock-share-pct').val() || '0')),
            show_serials: $('#stock-share-show-serials').is(':checked') ? 1 : 0,
            show_counts: $('#stock-share-show-counts').is(':checked') ? 1 : 0,
            filters: {
                warehouse_ids: checkedValues('warehouse').map(Number),
                collection_ids: checkedValues('collection').map(Number),
                include_singles: checkedValues('singles').length > 0,
                sizes: checkedValues('size'),
            },
        };
        if (!payload.title) {
            UI.showError('نام لینک را وارد کنید (مثلاً نام همکار).');
            $('#stock-share-title').trigger('focus');
            return;
        }
        $('#stock-share-save').prop('disabled', true);
        const result = await Api.call('save_stock_share_link', payload);
        $('#stock-share-save').prop('disabled', false);
        if (!result?.success) return;
        UI.showSuccess(result.message || 'ذخیره شد.');
        await load();
        resetForm();
        if (!payload.id && result.url) copyUrl(result.url);
    }

    async function copyUrl(url) {
        try {
            await navigator.clipboard.writeText(url);
            UI.showSuccess('لینک کپی شد.');
        } catch (err) {
            window.prompt('لینک را کپی کنید:', url);
        }
    }

    function whatsappText(link) {
        return `لیست موجودی${link.price_mode === 'none' ? '' : ' و قیمت'} فرش‌های ما (به‌روز، با عکس):\n${link.url}`;
    }

    async function revoke(id) {
        const link = state.links.find(l => Number(l.id) === Number(id));
        if (!link) return;
        if (!await UI.confirmText(`لینک «${link.title}» باطل شود؟ همکار دیگر نمی‌تواند آن را باز کند و این کار برگشت ندارد.`)) return;
        const result = await Api.call('revoke_stock_share_link', { id });
        if (result?.success) {
            UI.showSuccess(result.message || 'لینک باطل شد.');
            if (Number($('#stock-share-id').val()) === Number(id)) resetForm();
            await load();
        }
    }

    function init() {
        if (initialized) return;
        initialized = true;
        $('#stock-share-btn').on('click', open);
        $('#stock-share-form').on('submit', save);
        $('#stock-share-cancel-edit').on('click', resetForm);
        $(document).on('change', 'input[name="stock_share_price_mode"]', syncModeUi);
        $('#stock-share-links')
            .on('click', '.stock-share-copy', function () {
                const link = state.links.find(l => Number(l.id) === Number($(this).data('id')));
                if (link) copyUrl(link.url);
            })
            .on('click', '.stock-share-wa', function () {
                const link = state.links.find(l => Number(l.id) === Number($(this).data('id')));
                if (link) window.open('https://wa.me/?text=' + encodeURIComponent(whatsappText(link)), '_blank', 'noopener');
            })
            .on('click', '.stock-share-edit', function () { editLink($(this).data('id')); })
            .on('click', '.stock-share-revoke', function () { revoke($(this).data('id')); });
    }

    return { init, open };
})();
