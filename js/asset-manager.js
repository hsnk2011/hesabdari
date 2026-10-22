// /js/asset-manager.js
// دارایی‌ها: holdings that are not carpets (currency, coins, gold, furniture,
// equipment, ...). Simple buy/sell forms with invoice-style payments, today's
// prices from tgju, and moving a purchase invoice here.

const AssetManager = (function () {
    const esc = (v) => UI.escapeHtml(v == null ? '' : String(v));
    const money = (v) => UI.formatCurrency(Number(v) || 0);
    const faDate = (d) => (d ? UI.gregorianToPersian(String(d).slice(0, 10)) : '-');
    const num = (v) => Number(UI.toEnglishDigits(String(v ?? '')).replace(/[,٬\s]/g, '')) || 0;
    const qtyText = (q) => (Number(q) || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
    const DEFAULT_PRICE_KEYS = ['price_dollar_rl', 'price_eur', 'sekee', 'geram18'];
    const CATEGORIES = ['ارز', 'طلا و سکه', 'اثاثیه', 'تجهیزات', 'خودرو', 'سایر'];
    // Same rules as Asset::guessFromName on the server.
    const GUESSES = [
        ['دلار', 'ارز', 'price_dollar_rl', 'دلار'], ['یورو', 'ارز', 'price_eur', 'یورو'], ['درهم', 'ارز', 'price_aed', 'درهم'],
        ['پوند', 'ارز', 'price_gbp', 'پوند'], ['لیر', 'ارز', 'price_try', 'لیر'],
        ['نیم سکه', 'طلا و سکه', 'nim', 'عدد'], ['ربع سکه', 'طلا و سکه', 'rob', 'عدد'], ['سکه گرمی', 'طلا و سکه', 'gerami', 'عدد'],
        ['بهار آزادی', 'طلا و سکه', 'sekeb', 'عدد'], ['سکه', 'طلا و سکه', 'sekee', 'عدد'], ['مثقال', 'طلا و سکه', 'mesghal', 'مثقال'],
        ['طلای 24', 'طلا و سکه', 'geram24', 'گرم'], ['طلای ۲۴', 'طلا و سکه', 'geram24', 'گرم'], ['طلا', 'طلا و سکه', 'geram18', 'گرم'],
    ];
    const guess = (name) => {
        const g = GUESSES.find(([needle]) => String(name || '').includes(needle));
        return g ? { category: g[1], price_key: g[2], unit: g[3] } : { category: 'سایر', price_key: '', unit: 'عدد' };
    };
    const storage = {
        get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } },
    };

    let assets = [];
    let categories = CATEGORIES.slice();
    let prices = [];
    let showAllPrices = false;
    let loaded = false;
    let formKey = null;

    const priceByKey = () => Object.fromEntries(prices.map(p => [p.key, p]));

    async function load() {
        const [res, docs] = await Promise.all([
            Api.call('list_assets', { include_archived: $('#assets-show-archived').is(':checked') ? 1 : 0 }),
            Api.call('list_asset_documents', { doc_type: $('#asset-docs-filter').val() || '' }),
        ]);
        if (res?.success) {
            assets = res.assets || [];
            categories = res.categories && res.categories.length ? res.categories : CATEGORIES.slice();
            prices = res.prices || [];
            renderSummary();
            renderAssets();
            renderPrices();
        }
        if (docs?.success) renderDocs(docs.rows || []);
        loaded = true;
    }

    // ---------------- Lists ----------------
    function renderSummary() {
        const held = assets.filter(a => Number(a.quantity) > 0);
        const valued = held.filter(a => a.current_value !== null);
        const value = valued.reduce((s, a) => s + Number(a.current_value || 0), 0);
        const cost = held.reduce((s, a) => s + Number(a.cost_basis || 0), 0);
        const gain = value - valued.reduce((s, a) => s + Number(a.cost_basis || 0), 0);
        const missing = held.length - valued.length;
        // One compact card: three short lines on phones, three columns on wider screens.
        const item = (title, val, note = '', cls = '') => `<div class="col-12 col-md-4">
            <div class="d-flex d-md-block justify-content-between align-items-baseline gap-2">
                <div class="small text-muted">${title}${note ? `<span class="d-none d-md-inline"> — ${note}</span>` : ''}</div>
                <div class="fw-bold fs-md-5 ${cls}">${val}</div>
            </div></div>`;
        $('#assets-summary').html(`<div class="col-12"><div class="border rounded p-2 p-md-3"><div class="row g-2">${
            item('ارزش امروز', money(value), missing > 0 ? `${missing} مورد بدون ارزش روز` : '') +
            item('مبلغ خرید', money(cost)) +
            item('سود / زیان اگر امروز بفروشید', money(gain), '', gain >= 0 ? 'text-success' : 'text-danger')
        }</div></div></div>`);
    }

    function renderAssets() {
        const $tb = $('#assets-table tbody').empty();
        if (!assets.length) {
            $tb.html('<tr><td colspan="6" class="text-center text-muted py-4">هنوز چیزی ثبت نشده است.<br>برای شروع روی «خرید» بزنید، یا از «بیشتر» چیزهایی را که از قبل دارید ثبت کنید.</td></tr>');
            return;
        }
        assets.forEach(a => {
            const held = Number(a.quantity) || 0;
            const unitPrice = a.value_source === 'tgju' ? `<div class="small text-muted">هر ${esc(a.unit)}: ${money(a.unit_price_now)}</div>` : '';
            const value = a.current_value === null
                ? '<span class="text-muted small">—</span>'
                : `${money(a.current_value)}${a.value_source === 'manual' ? '<div class="small text-muted">تخمین دستی</div>' : unitPrice}`;
            const ug = a.unrealized_gain;
            const gain = ug === null ? '<span class="text-muted small">—</span>' : `<span class="${ug >= 0 ? 'text-success' : 'text-danger'}">${money(ug)}</span>`;
            $tb.append(`<tr class="${a.is_archived == 1 ? 'opacity-50' : ''}">
                <td><div class="fw-bold">${esc(a.name)}</div><div class="small text-muted">${esc(a.category || '')}<span class="d-md-none"> — ${qtyText(held)} ${esc(a.unit)}</span></div></td>
                <td class="text-nowrap d-none d-md-table-cell">${qtyText(held)} ${esc(a.unit)}</td>
                <td class="d-none d-md-table-cell">${money(a.cost_basis)}</td>
                <td>${value}${ug !== null ? `<div class="small d-md-none">${ug >= 0 ? 'سود' : 'زیان'}: <span class="${ug >= 0 ? 'text-success' : 'text-danger'}">${money(Math.abs(ug))}</span></div>` : ''}</td>
                <td class="d-none d-md-table-cell">${gain}</td>
                <td class="text-end">
                    <div class="d-flex flex-column flex-md-row gap-1 justify-content-end align-items-stretch align-items-md-center">
                    <button class="btn btn-sm btn-outline-primary asset-buy-one" data-id="${a.id}">خرید</button>
                    <button class="btn btn-sm btn-outline-success asset-sell-one" data-id="${a.id}" ${held > 0 ? '' : 'disabled'}>فروش</button>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false" title="بیشتر"><i class="bi bi-three-dots-vertical"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button class="dropdown-item asset-history" data-id="${a.id}"><i class="bi bi-clock-history me-2"></i>تاریخچه</button></li>
                            <li><button class="dropdown-item asset-edit" data-id="${a.id}"><i class="bi bi-pencil me-2"></i>ویرایش</button></li>
                            ${Number(a.movements) === 0 ? `<li><button class="dropdown-item text-danger asset-delete" data-id="${a.id}"><i class="bi bi-trash me-2"></i>حذف</button></li>` : ''}
                        </ul>
                    </div>
                    </div>
                </td>
            </tr>`);
        });
    }

    function renderPrices() {
        const used = new Set(assets.map(a => a.price_key).filter(Boolean));
        const list = showAllPrices ? prices : prices.filter(p => used.has(p.key) || DEFAULT_PRICE_KEYS.includes(p.key));
        const $tb = $('#asset-prices-table tbody').empty();
        list.forEach(p => {
            $tb.append(`<tr><td>${esc(p.title)}</td><td class="text-end text-nowrap">${p.price !== null ? money(p.price) : '<span class="text-muted">—</span>'}</td></tr>`);
        });
        $('#asset-prices-toggle').text(showAllPrices ? 'کمتر' : 'همه قیمت‌ها');
        const latest = prices.map(p => p.fetched_at).filter(Boolean).sort().pop();
        $('#asset-prices-footer').text(latest
            ? `از tgju.org — ${faDate(latest)} ساعت ${String(latest).slice(11, 16)}`
            : 'هنوز قیمتی گرفته نشده؛ از «بیشتر ← به‌روزرسانی قیمت‌ها» بگیرید.');
    }

    function renderDocs(rows) {
        const $tb = $('#asset-docs-table tbody').empty();
        if (!rows.length) {
            $tb.html('<tr><td colspan="5" class="text-center text-muted py-3">هنوز خرید یا فروشی ثبت نشده است.</td></tr>');
            return;
        }
        rows.forEach(d => {
            const isSale = d.doc_type === 'sale';
            const isOpening = Number(d.is_opening) === 1;
            const badge = isSale ? '<span class="badge text-bg-success">فروش</span>'
                : isOpening ? '<span class="badge text-bg-secondary">از قبل</span>' : '<span class="badge text-bg-primary">خرید</span>';
            const remaining = Number(d.remaining) || 0;
            const gain = Number(d.gain) || 0;
            const status = isOpening ? '<span class="text-muted small">—</span>'
                : Math.abs(remaining) > 0.5 ? `<span class="text-warning">مانده ${money(remaining)}</span>` : '<span class="text-success">تسویه</span>';
            $tb.append(`<tr>
                <td class="small d-none d-md-table-cell">${faDate(d.date)}</td>
                <td>${badge} ${esc(d.items_summary || '')}<div class="small text-muted"><span class="d-md-none">${faDate(d.date)} </span>${d.person_name ? `${isSale ? 'به' : 'از'} ${esc(d.person_name)}` : ''}</div></td>
                <td>${money(d.total_amount)}${isSale ? `<div class="small ${gain >= 0 ? 'text-success' : 'text-danger'}">${gain >= 0 ? 'سود' : 'زیان'}: ${money(Math.abs(gain))}</div>` : ''}<div class="small d-md-none">${status}</div></td>
                <td class="d-none d-md-table-cell">${status}</td>
                <td class="text-end">
                    <div class="btn-group">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button class="dropdown-item asset-doc-edit" data-id="${d.id}"><i class="bi bi-pencil me-2"></i>ویرایش</button></li>
                            <li><button class="dropdown-item text-danger asset-doc-delete" data-id="${d.id}" data-label="${esc((isSale ? 'فروش ' : 'خرید ') + (d.items_summary || ''))}"><i class="bi bi-trash me-2"></i>حذف</button></li>
                        </ul>
                    </div>
                </td>
            </tr>`);
        });
    }

    // ---------------- Asset definition ----------------
    async function editAsset(asset = null) {
        const cats = categories.includes(asset?.category) || !asset?.category ? categories : categories.concat([asset.category]);
        const priceOptions = [{ value: '', label: 'ندارد — ارزش را خودم وارد می‌کنم' }]
            .concat(prices.map(p => ({ value: p.key, label: p.title })));
        const fields = [
            { name: 'name', label: 'نام', value: asset?.name || '', required: true, placeholder: 'مثلاً دلار، سکه امامی، میز، لپ‌تاپ' },
            { name: 'category', label: 'دسته', type: 'select', value: asset?.category || 'سایر', options: cats.map(c => ({ value: c, label: c })) },
            { name: 'unit', label: 'واحد', value: asset?.unit || 'عدد', placeholder: 'عدد، دلار، گرم، ...' },
            { name: 'price_key', label: 'قیمت روز از tgju', type: 'select', value: asset?.price_key ?? '', options: priceOptions },
            { name: 'manual_value', label: 'ارزش امروز (اگر قیمت روز ندارد)', type: 'money', value: asset?.manual_value ?? '', help: 'مثلاً اگر الان بفروشید چقدر می‌ارزد؛ برای میز، خودرو و …' },
        ];
        if (asset) fields.push({ name: 'is_archived', label: 'بایگانی شود (در فهرست نمایش داده نشود)', type: 'checkbox', value: asset.is_archived == 1 });
        const v = await UI.formDialog({ title: asset ? 'ویرایش دارایی' : 'تعریف دارایی', fields, submitText: 'ذخیره' });
        if (!v) return;
        if (!asset) {
            // A new asset whose name says what it is gets unit/price automatically.
            const g = guess(v.name);
            if (v.unit === 'عدد' && g.unit !== 'عدد') v.unit = g.unit;
            if (!v.price_key && g.price_key) v.price_key = g.price_key;
            if (v.category === 'سایر' && g.category !== 'سایر') v.category = g.category;
        }
        const res = await Api.call('save_asset', {
            ...v,
            id: asset?.id || null,
            manual_value: v.manual_value ? v.manual_value : '',
            manual_value_date: v.manual_value ? new Date().toISOString().slice(0, 10) : '',
            notes: asset?.notes || '',
        });
        if (res?.success) { UI.showSuccess('ذخیره شد.'); load(); }
    }

    async function deleteAsset(id) {
        const a = assets.find(x => String(x.id) === String(id));
        if (!a || !(await UI.confirm(`«${esc(a.name)}» حذف شود؟`, 'حذف'))) return;
        const res = await Api.call('delete_asset', { id });
        if (res?.success) { UI.showSuccess('حذف شد.'); load(); }
    }

    async function showHistory(id) {
        const a = assets.find(x => String(x.id) === String(id));
        const res = await Api.call('get_asset_history', { id });
        if (!res?.success) return;
        const rows = (res.rows || []).map(r => {
            const isSale = r.doc_type === 'sale';
            return `<tr>
                <td class="text-nowrap">${faDate(r.date)}</td>
                <td>${isSale ? 'فروش' : 'خرید'}${r.person_name ? ` <span class="text-muted small">(${esc(r.person_name)})</span>` : ''}</td>
                <td class="text-nowrap">${isSale ? '−' : '+'}${qtyText(r.quantity)}</td>
                <td class="text-nowrap">${money(r.amount)}</td>
                <td class="text-nowrap">${isSale ? `<span class="${Number(r.gain_amount) >= 0 ? 'text-success' : 'text-danger'}">${money(r.gain_amount)}</span>` : ''}</td>
                <td class="text-nowrap">${qtyText(r.running_quantity)}</td>
            </tr>`;
        }).join('');
        const html = rows
            ? `<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>تاریخ</th><th></th><th>مقدار</th><th>مبلغ</th><th>سود/زیان</th><th>باقی‌مانده</th></tr></thead><tbody>${rows}</tbody></table></div>`
            : '<p class="text-muted mb-0">هنوز خرید یا فروشی ثبت نشده است.</p>';
        await UI.formDialog({ title: `تاریخچه ${a ? a.name : ''}${a ? ` (${a.unit})` : ''}`, message: html, fields: [], submitText: 'بستن', size: 'modal-lg' });
    }

    // ---------------- Buy / sell form (same layout as the invoice forms) ----------------
    // Interactive elements use asset-* classes: the invoice manager has body-level
    // handlers on .quantity, .payment-type, .remove-item-btn, ... that must not fire here.
    const docType = () => $('#asset-doc-type').val();
    const isOpening = () => docType() === 'purchase' && $('#asset-doc-opening').is(':checked');

    function assetOptions(selectedId) {
        const forSale = docType() === 'sale';
        let html = '<option value="">انتخاب دارایی...</option>';
        assets.filter(a => a.is_archived != 1 || String(a.id) === String(selectedId)).forEach(a => {
            const held = Number(a.quantity) || 0;
            if (forSale && held <= 0 && String(a.id) !== String(selectedId)) return;
            const label = forSale ? `${a.name} (موجود: ${qtyText(held)} ${a.unit})` : a.name;
            html += `<option value="${a.id}" ${String(a.id) === String(selectedId) ? 'selected' : ''}>${esc(label)}</option>`;
        });
        if (!forSale) html += '<option value="__new">+ دارایی جدید...</option>';
        return html;
    }

    function renumber(containerSel) {
        $(containerSel).children('.row').each(function (idx) {
            $(this).find('.invoice-row-number').text(idx + 1);
        });
    }

    function addItemRow(item = null) {
        const $row = $(`<div class="row asset-item-row invoice-item-card align-items-end g-2">
            <div class="col-12 invoice-row-toolbar d-md-none">
                <span class="invoice-row-label"><span class="invoice-row-number badge text-bg-secondary me-1">0</span><i class="bi bi-gem me-1"></i>قلم</span>
                <button type="button" class="btn btn-outline-danger btn-sm asset-item-remove" title="حذف"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="col-auto d-none d-md-flex invoice-item-row-num align-items-end">
                <span class="invoice-row-number" title="شماره ردیف">0</span>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">دارایی</label>
                <select class="form-select asset-item-asset">${assetOptions(item?.asset_id)}</select>
                <input type="text" class="form-control mt-1 asset-new-name d-none" placeholder="نام دارایی جدید، مثلاً دلار، سکه امامی، میز">
            </div>
            <div class="col-6 col-md-2"><label class="form-label">مقدار <span class="asset-item-unit text-muted small"></span></label><input type="text" inputmode="decimal" class="form-control asset-item-qty" value="${item ? qtyText(item.quantity) : ''}"></div>
            <div class="col-6 col-md-2"><label class="form-label">قیمت واحد</label><input type="text" inputmode="numeric" class="form-control asset-item-price numeric-input" value="${item ? Number(item.unit_price).toLocaleString('en-US') : ''}"></div>
            <div class="col-10 col-md-2"><label class="form-label">جمع</label><input type="text" class="form-control item-total asset-item-amount" readonly></div>
            <div class="col-md-1 d-none d-md-flex align-items-end"><button type="button" class="btn btn-danger btn-sm asset-item-remove" title="حذف"><i class="bi bi-x-lg"></i></button></div>
            <div class="col-12 small text-muted asset-item-hint"></div>
            <div class="col-12 asset-item-advanced d-none">
                <div class="row g-2 bg-light rounded p-2 m-0">
                    <div class="col-md-4"><label class="form-label-sm">دسته</label><select class="form-select form-select-sm asset-new-category">${categories.map(c => `<option>${esc(c)}</option>`).join('')}</select></div>
                    <div class="col-md-3"><label class="form-label-sm">واحد</label><input type="text" class="form-control form-control-sm asset-new-unit" value="عدد"></div>
                    <div class="col-md-5"><label class="form-label-sm">قیمت روز (tgju)</label><select class="form-select form-select-sm asset-new-price-key"><option value="">ندارد</option>${prices.map(p => `<option value="${esc(p.key)}">${esc(p.title)}</option>`).join('')}</select></div>
                </div>
            </div>
        </div>`);
        $('#asset-doc-items').append($row);
        $row.find('.asset-new-category').val('سایر');
        refreshItemRow($row, !item);
        renumber('#asset-doc-items');
        return $row;
    }

    // Unit, price and hints for one row. autofillPrice puts today's price in an empty price box.
    function refreshItemRow($row, autofillPrice = false) {
        const id = $row.find('.asset-item-asset').val();
        const isNew = id === '__new';
        $row.find('.asset-new-name').toggleClass('d-none', !isNew);
        if (!isNew) $row.find('.asset-item-advanced').addClass('d-none');
        const a = assets.find(x => String(x.id) === String(id));
        const priceKey = isNew ? $row.find('.asset-new-price-key').val() : a?.price_key;
        const unit = isNew ? ($row.find('.asset-new-unit').val() || '') : (a?.unit || '');
        $row.find('.asset-item-unit').text(unit ? `(${unit})` : '');
        const p = priceKey ? priceByKey()[priceKey] : null;
        if (autofillPrice && p && p.price && !num($row.find('.asset-item-price').val())) {
            $row.find('.asset-item-price').val(Number(p.price).toLocaleString('en-US'));
        }
        const hints = [];
        if (isNew) {
            const cat = $row.find('.asset-new-category').val();
            hints.push(`دسته: ${esc(cat)}، واحد: ${esc(unit || 'عدد')}${priceKey ? '، قیمت روز خودکار' : ''} — <a href="#" class="asset-item-advanced-toggle">تغییر</a>`);
        } else if (a && docType() === 'sale') {
            hints.push(`موجود: ${qtyText(a.quantity)} ${esc(a.unit)} — میانگین قیمت خرید: ${money(a.avg_cost)}`);
        }
        if (p && p.price) hints.push(`قیمت امروز: ${money(p.price)}`);
        $row.find('.asset-item-hint').html(hints.join(' — '));
        const amount = num($row.find('.asset-item-qty').val()) * num($row.find('.asset-item-price').val());
        $row.find('.asset-item-amount').val(amount ? Math.round(amount).toLocaleString('en-US') : '');
        updateTotals();
    }

    function onNewNameInput($row) {
        if ($row.data('advanced-touched')) return;
        const g = guess($row.find('.asset-new-name').val());
        $row.find('.asset-new-category').val(g.category);
        $row.find('.asset-new-unit').val(g.unit);
        $row.find('.asset-new-price-key').val(g.price_key);
        refreshItemRow($row, true);
    }

    function defaultAccountId() {
        const accounts = App.getCache().accounts || [];
        const last = storage.get('asset_last_account');
        if (last && accounts.some(a => String(a.id) === last)) return last;
        return accounts.length ? String(accounts[0].id) : '';
    }

    function checkOptions(selectedId, details) {
        const list = (App.getCache().checks || []).filter(c => c.type === 'received' && (c.status === 'in_hand' || String(c.id) === String(selectedId)));
        if (!list.length && !selectedId) return null;
        let html = '<option value="">-- انتخاب کنید --</option>';
        list.forEach(c => { html += `<option value="${c.id}" data-amount="${c.amount}" ${String(c.id) === String(selectedId) ? 'selected' : ''}>${esc(c.checkNumber)} - ${money(c.amount)}</option>`; });
        if (selectedId && !list.some(c => String(c.id) === String(selectedId)) && details) {
            html += `<option value="${selectedId}" data-amount="${details.amount}" selected>${esc(details.checkNumber)} - ${money(details.amount)} (خرج شده)</option>`;
        }
        return html;
    }

    function addPaymentRow(p = null) {
        const isSale = docType() === 'sale';
        const chkStatus = p?.checkDetails?.status;
        const locked = !!p && (p.type === 'check_bounce'
            || (p.type === 'check' && chkStatus && !['in_hand', 'payable'].includes(chkStatus))
            || (p.type === 'endorse_check' && chkStatus && chkStatus !== 'endorsed'));
        const statusLabels = { cashed: 'وصول/پاس شده', endorsed: 'خرج شده', bounced: 'برگشتی', in_hand: 'نزد ما' };
        const lockNote = p?.type === 'check_bounce'
            ? '<i class="bi bi-arrow-return-left"></i> سند برگشت/عودت چک — فقط از بخش چک‌ها قابل لغو است'
            : (locked ? `<i class="bi bi-lock"></i> چک در وضعیت «${statusLabels[chkStatus] || chkStatus}» است و از روی سند قابل تغییر نیست` : '');
        const $row = $(`<div class="row asset-pay-row invoice-payment-card align-items-end g-2 ${locked ? 'is-locked-payment' : ''}" data-existing-id="${p?.id || ''}" data-check-id="${p?.checkId || ''}">
            <div class="col-12 invoice-row-toolbar d-md-none">
                <span class="invoice-row-label"><span class="invoice-row-number badge text-bg-secondary me-1">0</span><i class="bi bi-wallet2 me-1"></i>ردیف ${isSale ? 'دریافت' : 'پرداخت'}</span>
                <button type="button" class="btn btn-outline-danger btn-sm asset-pay-remove" title="حذف" ${locked ? 'disabled' : ''}><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="col-auto d-none d-md-flex invoice-item-row-num pay-col-num align-items-end"><span class="invoice-row-number" title="شماره ردیف">0</span></div>
            <div class="col-6 col-md-2 pay-col-type"><label class="form-label">نوع</label><select class="form-select asset-pay-type">
                <option value="cash">نقد</option>
                <option value="check">چک جدید</option>
                ${isSale ? '' : '<option value="endorse_check">خرج چک دریافتی</option>'}
                ${p?.type === 'check_bounce' ? '<option value="check_bounce">برگشت/عودت چک</option>' : ''}
            </select></div>
            <div class="col-6 col-md-2 pay-col-amount"><label class="form-label">مبلغ</label><input type="text" inputmode="numeric" class="form-control asset-pay-amount numeric-input" value="${p ? Number(p.amount).toLocaleString('en-US') : '0'}"></div>
            <div class="col-6 col-md-2 pay-col-date"><label class="form-label">تاریخ</label><input type="text" class="form-control persian-datepicker asset-pay-date" value="${p ? faDate(p.date) : ($('#asset-doc-date').val() || UI.today())}"></div>
            <div class="col-12 col-md-5 payment-details-container pay-col-details asset-pay-details"></div>
            <div class="col-md-1 d-none d-md-flex align-items-end pay-col-remove"><button type="button" class="btn btn-danger btn-sm asset-pay-remove" title="حذف" ${locked ? 'disabled' : ''}><i class="bi bi-x-lg"></i></button></div>
            ${lockNote ? `<div class="col-12"><small class="text-warning">${lockNote}</small></div>` : ''}
        </div>`);
        $('#asset-doc-payments').append($row);
        $row.find('.asset-pay-type').val(p?.type || 'cash');
        renderPayDetails($row, p);
        if (locked) $row.find('input, select').prop('disabled', true);
        UI.initializeDatepickers();
        renumber('#asset-doc-payments');
        updateTotals();
        return $row;
    }

    function renderPayDetails($row, p = null) {
        const type = $row.find('.asset-pay-type').val();
        const isSale = docType() === 'sale';
        const $d = $row.find('.asset-pay-details').empty();
        $row.find('.asset-pay-amount').prop('readonly', type === 'endorse_check' || type === 'check_bounce');
        if (type === 'check') {
            const c = p?.checkDetails || {};
            $d.html(`<div class="row g-2 check-details-grid">
                <div class="col-6 col-md-3 pay-col-check-no"><label class="form-label-sm">شماره چک</label><input type="text" class="form-control form-control-sm asset-chk-number" inputmode="numeric" autocomplete="off" value="${esc(c.checkNumber || '')}"></div>
                <div class="col-6 col-md-3 pay-col-due"><label class="form-label-sm">تاریخ سررسید</label><input type="text" class="form-control form-control-sm persian-datepicker asset-chk-due" value="${c.dueDate ? faDate(c.dueDate) : UI.today()}"></div>
                <div class="col-6 col-md-3 pay-col-bank"><label class="form-label-sm">نام بانک</label><input type="text" class="form-control form-control-sm asset-chk-bank" value="${esc(c.bankName || '')}"></div>
                <div class="col-6 col-md-3 pay-col-issuer"><label class="form-label-sm">صادرکننده</label><input type="text" class="form-control form-control-sm asset-chk-issuer" value="${esc(c.issuerName || '')}" placeholder="نام روی چک"></div>
            </div>`);
            UI.initializeDatepickers();
        } else if (type === 'endorse_check') {
            const options = checkOptions(p?.checkId, p?.checkDetails);
            $d.html(options === null
                ? '<p class="text-danger small mt-2 mb-0">چک قابل واگذاری وجود ندارد.</p>'
                : `<label class="form-label-sm">انتخاب چک</label><select class="form-select form-select-sm asset-endorse-select">${options}</select>`);
        } else if (type === 'check_bounce') {
            $d.html(`<div class="small text-muted pt-2">${esc(p?.description || 'برگشت چک')}</div>`);
        } else {
            const accounts = App.getCache().accounts || [];
            $d.html(`<div class="row g-2">
                <div class="col-12 col-md-7 pay-col-account"><label class="form-label-sm">${isSale ? 'واریز به حساب' : 'پرداخت از حساب'}</label><select class="form-select form-select-sm asset-pay-account">${accounts.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select></div>
                <div class="col-12 col-md-5 pay-col-note"><label class="form-label-sm">توضیحات</label><input type="text" class="form-control form-control-sm asset-pay-desc" value="${esc(p?.description || '')}"></div>
            </div>`);
            $d.find('.asset-pay-account').val(p?.account_id ? String(p.account_id) : defaultAccountId());
        }
    }

    function itemsTotal() {
        let total = 0;
        $('#asset-doc-items .asset-item-row').each(function () {
            total += num($(this).find('.asset-item-qty').val()) * num($(this).find('.asset-item-price').val());
        });
        return Math.round(total);
    }

    function paidTotal() {
        let paid = 0;
        $('#asset-doc-payments .asset-pay-row').each(function () { paid += num($(this).find('.asset-pay-amount').val()); });
        return paid;
    }

    function updateTotals() {
        const total = itemsTotal();
        const paid = isOpening() ? 0 : paidTotal();
        const rem = isOpening() ? 0 : total - paid;
        const count = $('#asset-doc-items .asset-item-row').filter(function () { return !!$(this).find('.asset-item-asset').val(); }).length;
        $('#asset-doc-item-count').text(count.toLocaleString('fa-IR'));
        $('#asset-doc-total').text(money(total));
        $('#asset-doc-paid').text(money(paid));
        $('#asset-doc-remaining').text(money(rem));
        let hint = '';
        if (!isOpening() && rem > 0.5) {
            const person = $('#asset-doc-person').val() ? $('#asset-doc-person option:selected').text() : '';
            hint = person
                ? `باقی‌مانده به‌صورت نسیه به حساب «${esc(person)}» ثبت می‌شود.`
                : `برای ثبت باقی‌مانده به‌صورت نسیه، ${docType() === 'sale' ? 'خریدار' : 'فروشنده'} را انتخاب کنید.`;
        }
        $('#asset-doc-remaining-hint').html(hint);
    }

    function fillPersonSelect(selected) {
        const cache = App.getCache();
        const $sel = $('#asset-doc-person').empty().append('<option value=""></option>');
        [['supplier', 'تأمین‌کنندگان', cache.suppliers], ['customer', 'مشتریان', cache.customers], ['contact', 'سایر اشخاص', cache.contacts]].forEach(([type, label, list]) => {
            if (!list || !list.length) return;
            const $g = $(`<optgroup label="${label}"></optgroup>`);
            list.forEach(p => $g.append(`<option value="${type}:${p.id}">${esc(p.name)}</option>`));
            $sel.append($g);
        });
        $sel.val(selected || '');
        if ($.fn.select2) {
            try { $sel.select2('destroy'); } catch (e) { /* not initialised */ }
            $sel.select2({
                theme: 'bootstrap-5', dir: 'rtl', width: '100%', allowClear: true,
                placeholder: docType() === 'sale' ? 'انتخاب خریدار (اختیاری)...' : 'انتخاب فروشنده (اختیاری)...',
                dropdownParent: $('#assetDocModal'),
            });
        }
    }

    function applyMode() {
        const sale = docType() === 'sale';
        const opening = isOpening();
        $('#asset-doc-opening-wrap').toggle(!sale);
        $('#asset-doc-person-wrap').toggle(!opening);
        $('#asset-doc-pay-section').toggle(!opening);
        $('#asset-doc-paid, #asset-doc-remaining').closest('li').toggle(!opening);
        if (opening) {
            $('#asset-doc-payments').empty();
            $('#asset-doc-person').val('').trigger('change.select2');
        }
        $('#asset-doc-person-label').text(sale ? 'خریدار' : 'فروشنده');
        $('#asset-doc-pay-title').text(sale ? 'بخش دریافت' : 'بخش پرداخت');
        $('#asset-doc-add-payment').html(`<i class="bi bi-plus"></i> افزودن ردیف ${sale ? 'دریافت' : 'پرداخت'}`);
        $('#asset-doc-paid-label').text(sale ? 'دریافتی' : 'پرداختی');
        updateTotals();
    }

    async function openDoc(type, opts = {}) {
        if (!loaded) await load();
        $('#asset-doc-error').addClass('d-none').text('');
        $('#asset-doc-items, #asset-doc-payments').empty();
        let doc = null;
        if (opts.id) {
            const res = await Api.call('get_asset_document', { id: opts.id });
            if (!res?.success) return;
            doc = res.document;
            type = doc.doc_type;
        }
        $('#asset-doc-type').val(type);
        $('#asset-doc-id').val(doc ? doc.id : '');
        $('#asset-doc-number').val(doc ? (doc.doc_number || '') : '');
        $('#asset-doc-opening').prop('checked', doc ? Number(doc.is_opening) === 1 : !!opts.opening);
        const sale = type === 'sale';
        const what = isOpening() ? 'ثبت دارایی‌هایی که از قبل دارم' : (sale ? 'سند فروش دارایی' : 'سند خرید دارایی');
        $('#asset-doc-title').text(doc ? `ویرایش ${what}` : what);
        $('#asset-doc-date').val(doc ? faDate(doc.date) : UI.today());
        $('#asset-doc-description').val(doc ? (doc.description || '') : '');
        fillPersonSelect(doc && doc.person_id ? `${doc.person_type}:${doc.person_id}` : '');
        if (doc) {
            (doc.items || []).forEach(it => addItemRow(it));
            (doc.payments || []).forEach(p => addPaymentRow(p));
        } else {
            const $r = addItemRow(null);
            if (opts.assetId) {
                $r.find('.asset-item-asset').val(String(opts.assetId));
                refreshItemRow($r, true);
            }
        }
        applyMode();
        formKey = 'asset-' + Date.now() + '-' + Math.random().toString(36).slice(2);
        UI.initializeDatepickers();
        new bootstrap.Modal(document.getElementById('assetDocModal')).show();
    }

    function collectDoc() {
        const items = [];
        let error = null;
        $('#asset-doc-items .asset-item-row').each(function () {
            if (error) return;
            const $r = $(this);
            const assetVal = $r.find('.asset-item-asset').val();
            const quantity = num($r.find('.asset-item-qty').val());
            const unitPrice = num($r.find('.asset-item-price').val());
            if (!assetVal && !quantity) return;
            if (!assetVal) { error = 'دارایی هر ردیف را انتخاب کنید.'; return; }
            if (!(quantity > 0)) { error = 'مقدار هر ردیف باید بیشتر از صفر باشد.'; return; }
            const row = { quantity, unit_price: unitPrice };
            if (assetVal === '__new') {
                const name = $r.find('.asset-new-name').val().trim();
                if (!name) { error = 'نام دارایی جدید را وارد کنید.'; return; }
                row.new_asset = { name, category: $r.find('.asset-new-category').val(), unit: $r.find('.asset-new-unit').val().trim() || 'عدد', price_key: $r.find('.asset-new-price-key').val() };
            } else {
                row.asset_id = parseInt(assetVal, 10);
            }
            items.push(row);
        });
        const docDate = UI.toGregorian($('#asset-doc-date'));
        const payments = [];
        if (!isOpening()) {
            $('#asset-doc-payments .asset-pay-row').each(function () {
                if (error) return;
                const $r = $(this);
                const amount = num($r.find('.asset-pay-amount').val());
                if (amount <= 0) return;
                const type = $r.find('.asset-pay-type').val();
                const existing = parseInt($r.attr('data-existing-id'), 10) || null;
                const p = { type, amount, date: UI.toGregorian($r.find('.asset-pay-date')) || docDate };
                if (existing) p.existing_payment_id = existing;
                if (type === 'cash') {
                    p.account_id = $r.find('.asset-pay-account').val() || null;
                    p.description = $r.find('.asset-pay-desc').val() || '';
                    if (!p.account_id) { error = 'حساب را برای پرداخت نقدی انتخاب کنید.'; return; }
                } else if (type === 'check') {
                    p.checkId = parseInt($r.attr('data-check-id'), 10) || null;
                    p.checkDetails = {
                        checkNumber: $r.find('.asset-chk-number').val().trim(),
                        dueDate: UI.toGregorian($r.find('.asset-chk-due')),
                        bankName: $r.find('.asset-chk-bank').val().trim(),
                        issuerName: $r.find('.asset-chk-issuer').val().trim(),
                    };
                    if (!p.checkDetails.checkNumber) { error = 'شماره چک را وارد کنید.'; return; }
                } else if (type === 'endorse_check') {
                    p.checkId = parseInt($r.find('.asset-endorse-select').val(), 10) || null;
                    if (!p.checkId) { error = 'چک دریافتی را انتخاب کنید.'; return; }
                }
                payments.push(p);
            });
        }
        if (!items.length && !error) error = 'حداقل یک قلم وارد کنید.';
        const person = isOpening() ? '' : ($('#asset-doc-person').val() || '');
        const [personType, personId] = person ? person.split(':') : [null, null];
        return {
            error,
            data: {
                id: parseInt($('#asset-doc-id').val(), 10) || null,
                doc_type: docType(),
                date: docDate,
                doc_number: $('#asset-doc-number').val().trim(),
                is_opening: isOpening() ? 1 : 0,
                person_type: personType,
                person_id: personId ? parseInt(personId, 10) : null,
                description: $('#asset-doc-description').val().trim(),
                items,
                payments,
                _idempotency_key: formKey,
            },
        };
    }

    async function saveDoc() {
        const { error, data } = collectDoc();
        if (error) { $('#asset-doc-error').text(error).removeClass('d-none'); $('#assetDocModal .modal-body').scrollTop(0); return; }
        $('#asset-doc-error').addClass('d-none');
        const cashRow = data.payments.find(p => p.type === 'cash' && p.account_id);
        UI.showLoader();
        const res = await Api.call('save_asset_document', data);
        UI.hideLoader();
        if (res?.success) {
            if (cashRow) storage.set('asset_last_account', String(cashRow.account_id));
            bootstrap.Modal.getInstance(document.getElementById('assetDocModal'))?.hide();
            UI.showSuccess('سند ذخیره شد.');
            App.updateCache(['accounts', 'checks']).catch(() => {});
            await load();
        } else if (res?.error) {
            $('#asset-doc-error').text(res.error).removeClass('d-none');
            $('#assetDocModal .modal-body').scrollTop(0);
        }
    }

    async function deleteDoc(id, label) {
        if (!(await UI.confirm(`«${esc(label)}» حذف شود؟ پرداخت‌ها و دریافت‌های آن هم برگردانده می‌شوند.`, 'حذف'))) return;
        const res = await Api.call('delete_asset_document', { id });
        if (res?.success) {
            UI.showSuccess('حذف شد.');
            App.updateCache(['accounts', 'checks']).catch(() => {});
            load();
        }
    }

    async function refreshPrices() {
        UI.showLoader();
        const res = await Api.call('refresh_asset_prices', {});
        UI.hideLoader();
        if (res?.success) {
            if (res.errors && res.errors.length) UI.showError('بعضی قیمت‌ها گرفته نشد: ' + res.errors.join(' '));
            else UI.showSuccess('قیمت‌ها به‌روز شد.');
            await load();
        }
    }

    // ---------------- Move a purchase invoice here ----------------
    let convertSearchTimer = null;
    async function loadConvertList() {
        const $list = $('#asset-convert-list').html('<div class="text-muted small p-2">در حال بارگذاری...</div>');
        const res = await Api.call('list_convertible_purchase_invoices', { search: $('#asset-convert-search').val() || '' });
        if (!res?.success) { $list.empty(); return; }
        const rows = res.rows || [];
        if (!rows.length) { $list.html('<div class="text-muted small p-2">فاکتوری پیدا نشد.</div>'); return; }
        $list.html(`<div class="list-group">${rows.map(r => `
            <button type="button" class="list-group-item list-group-item-action asset-convert-pick" data-id="${r.id}">
                <div class="d-flex justify-content-between flex-wrap gap-1">
                    <span class="fw-bold">${esc(r.items || 'بدون قلم')}</span>
                    <span class="text-nowrap">${money(r.total)}</span>
                </div>
                <div class="small text-muted">فاکتور ${esc(r.invoice_number || ('#' + r.id))} — ${faDate(r.date)}${r.supplier_name ? ' — ' + esc(r.supplier_name) : ''}
                    ${Number(r.suggested) ? ' <span class="badge text-bg-warning">احتمالاً دارایی</span>' : ''}</div>
            </button>`).join('')}</div>`);
    }

    function openConvert() {
        $('#asset-convert-search').val('');
        new bootstrap.Modal(document.getElementById('assetConvertModal')).show();
        loadConvertList();
    }

    async function convertInvoice(invoiceId) {
        bootstrap.Modal.getInstance(document.getElementById('assetConvertModal'))?.hide();
        const prev = await Api.call('preview_asset_conversion', { invoice_id: invoiceId });
        if (!prev?.success) return;
        const inv = prev.invoice;
        const fields = prev.items.map((it, i) => ({
            name: `qty_${i}`,
            label: `${it.name} — ${money(it.amount)}: چند ${it.unit || 'عدد'}؟`,
            value: String(Number(it.quantity)),
            help: 'مقدار واقعی، مثلاً تعداد دلار یا سکه. مبلغ تغییر نمی‌کند.',
        }));
        const v = await UI.formDialog({
            title: 'انتقال به دارایی‌ها',
            message: `<div class="small">فاکتور ${esc(inv.invoice_number || ('#' + inv.id))} — ${faDate(inv.date)}${inv.supplier_name ? ' — ' + esc(inv.supplier_name) : ''}.<br>فاکتور و کالای آن از انبار حذف می‌شود؛ پرداخت‌ها و چک‌ها همان‌طور می‌مانند.</div>`,
            fields,
            submitText: 'انتقال',
            submitClass: 'btn-warning',
        });
        if (!v) return;
        const rows = prev.items.map((it, i) => ({ item_id: it.id, quantity: num(v[`qty_${i}`]) || it.quantity, unit: it.unit || 'عدد' }));
        UI.showLoader();
        const res = await Api.call('convert_purchase_to_asset', { invoice_id: inv.id, rows, _idempotency_key: 'conv-' + inv.id + '-' + Date.now() });
        UI.hideLoader();
        if (res?.success) {
            UI.showSuccess('به دارایی‌ها منتقل شد.');
            App.updateCache(['products']).catch(() => {});
            await load();
        }
    }

    function init() {
        $('#assets-tab').on('shown.bs.tab', load);
        $('#assets-show-archived').on('change', load);
        $('#asset-docs-filter').on('change', load);
        $('#asset-buy-btn').on('click', () => openDoc('purchase'));
        $('#asset-sell-btn').on('click', () => openDoc('sale'));
        $('#asset-opening-btn').on('click', () => openDoc('purchase', { opening: true }));
        $('#asset-new-btn').on('click', () => editAsset(null));
        $('#asset-refresh-prices-btn').on('click', refreshPrices);
        $('#asset-convert-btn').on('click', openConvert);
        $('#asset-prices-toggle').on('click', () => { showAllPrices = !showAllPrices; renderPrices(); });

        $('#assets-table').on('click', '.asset-buy-one', function () { openDoc('purchase', { assetId: $(this).data('id') }); })
            .on('click', '.asset-sell-one', function () { openDoc('sale', { assetId: $(this).data('id') }); })
            .on('click', '.asset-history', function () { showHistory($(this).data('id')); })
            .on('click', '.asset-edit', function () { editAsset(assets.find(a => String(a.id) === String($(this).data('id')))); })
            .on('click', '.asset-delete', function () { deleteAsset($(this).data('id')); });
        $('#asset-docs-table').on('click', '.asset-doc-edit', function () { openDoc(null, { id: $(this).data('id') }); })
            .on('click', '.asset-doc-delete', function () { deleteDoc($(this).data('id'), $(this).data('label')); });

        $('#asset-convert-search').on('input', () => { clearTimeout(convertSearchTimer); convertSearchTimer = setTimeout(loadConvertList, 300); });
        $('#asset-convert-list').on('click', '.asset-convert-pick', function () { convertInvoice($(this).data('id')); });

        const $m = $('#assetDocModal');
        $('#asset-doc-add-item').on('click', () => addItemRow());
        $('#asset-doc-add-payment').on('click', () => {
            // New payment row starts with what is still unpaid.
            const remaining = itemsTotal() - paidTotal();
            const $r = addPaymentRow(null);
            if (remaining > 0) $r.find('.asset-pay-amount').val(Math.round(remaining).toLocaleString('en-US'));
            updateTotals();
        });
        $('#asset-doc-opening').on('change', applyMode);
        $('#asset-doc-person').on('change', updateTotals);
        $m.on('change', '.asset-item-asset', function () {
            const $r = $(this).closest('.asset-item-row');
            refreshItemRow($r, true);
            if ($(this).val() === '__new') $r.find('.asset-new-name').trigger('focus');
        });
        $m.on('input', '.asset-new-name', function () { onNewNameInput($(this).closest('.asset-item-row')); });
        $m.on('change input', '.asset-new-category, .asset-new-unit, .asset-new-price-key', function () {
            const $r = $(this).closest('.asset-item-row');
            $r.data('advanced-touched', true);
            refreshItemRow($r, true);
        });
        $m.on('click', '.asset-item-advanced-toggle', function (e) {
            e.preventDefault();
            $(this).closest('.asset-item-row').find('.asset-item-advanced').toggleClass('d-none');
        });
        $m.on('input', '.asset-item-qty, .asset-item-price', function () { refreshItemRow($(this).closest('.asset-item-row')); });
        $m.on('click', '.asset-item-remove', function () {
            $(this).closest('.asset-item-row').remove();
            renumber('#asset-doc-items');
            updateTotals();
        });
        $m.on('change', '.asset-pay-type', function () { renderPayDetails($(this).closest('.asset-pay-row')); updateTotals(); });
        $m.on('input', '.asset-pay-amount', updateTotals);
        $m.on('change', '.asset-endorse-select', function () {
            const amount = Number($(this).find('option:selected').data('amount')) || 0;
            $(this).closest('.asset-pay-row').find('.asset-pay-amount').val(amount.toLocaleString('en-US'));
            updateTotals();
        });
        $m.on('click', '.asset-pay-remove', function () {
            $(this).closest('.asset-pay-row').remove();
            renumber('#asset-doc-payments');
            updateTotals();
        });
        $('#asset-doc-save').on('click', saveDoc);
    }

    return { init, load, openDoc };
})();

$(function () { AssetManager.init(); });
