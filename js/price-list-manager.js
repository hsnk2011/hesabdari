// /js/price-list-manager.js

const PriceListManager = (function () {
    let priceListData = {};
    let priceSyncLock = false;

    function canEditPrices() {
        return typeof App !== 'undefined' && typeof App.hasPermission === 'function'
            && App.hasPermission('manage_collections');
    }

    function parsePriceNumber(raw) {
        const n = parseFloat(UI.toEnglishDigits(String(raw ?? '')).replace(/,/g, '').trim());
        return Number.isFinite(n) ? n : NaN;
    }

    function formatPriceNumber(n) {
        if (!Number.isFinite(n)) return '';
        return Math.round(n).toLocaleString('en-US');
    }

    function dimensionArea(dim) {
        if (!dim) return NaN;
        let d = String(dim).trim();
        const isMeterUnit = /(^|\s)(m|متر)(\s|$)/i.test(d);
        const isCentimeterUnit = /(^|\s)(cm|سانتی|سانت)(\s|$)/i.test(d);
        if (isMeterUnit || isCentimeterUnit) {
            // Explicit unit suffix: honor it directly.
            d = d.replace(/[×*]/g, 'x').replace(/\s+/g, '');
            const parts = d.split('x');
            if (parts.length !== 2) return NaN;
            const w = parseFloat(parts[0].replace(/[^\d.]/g, ''));
            const h = parseFloat(parts[1].replace(/[^\d.]/g, ''));
            if (!(w > 0) || !(h > 0)) return NaN;
            if (isCentimeterUnit) {
                return (w / 100) * (h / 100);
            }
            return w * h;
        }
        // Shared rule: named-dimension map + per-side cm threshold from settings.
        const area = UI.dimensionArea(d.replace(/×/g, 'x'));
        return area > 0 ? area : NaN;
    }

    function itemDimensions(item) {
        return Object.keys(item?.calculated_prices || {})
            .filter(d => item.calculated_prices[d] !== null && Number.isFinite(dimensionArea(d)) && dimensionArea(d) > 0)
            .sort((a, b) => {
                const da = dimensionArea(a);
                const db = dimensionArea(b);
                if (isNaN(da) && isNaN(db)) return a.localeCompare(b);
                if (isNaN(da)) return 1;
                if (isNaN(db)) return -1;
                if (da !== db) return da - db;
                return a.localeCompare(b);
            });
    }

    function findItem(type, id) {
        const items = type === 'collection' ? priceListData.collections : priceListData.products;
        return (items || []).find(i => Number(i.id) === Number(id));
    }

    function editButtonHtml(extraClass, attrs, title) {
        return `<button type="button" class="btn btn-sm btn-outline-primary py-0 px-1 no-print ${extraClass}" ${attrs} title="${title}" aria-label="${title}">
            <i class="bi bi-pencil"></i>
        </button>`;
    }

    function renderMeterPriceCell(item, type) {
        const priceDisplay = item.has_no_price
            ? '<span class="text-danger fw-bold">تعیین نشده</span>'
            : UI.formatCurrency(item.price_per_meter);

        if (!canEditPrices()) {
            return `<td>${priceDisplay}</td>`;
        }

        return `<td class="price-per-meter-cell align-middle">
            <div class="d-flex align-items-center justify-content-center gap-1">
                <span class="price-value">${priceDisplay}</span>
                ${editButtonHtml('price-edit-btn', `data-id="${item.id}" data-type="${type}" data-focus="meter"`, 'تغییر قیمت')}
            </div>
        </td>`;
    }

    function renderStackedDimPrice(item, type, dim, priceText) {
        if (!canEditPrices()) {
            return `<div>${priceText}</div>`;
        }
        const safeDim = UI.escapeHtml(dim);
        return `<div class="d-flex align-items-center justify-content-center gap-1">
            <span>${priceText}</span>
            ${editButtonHtml('price-edit-btn', `data-id="${item.id}" data-type="${type}" data-focus="total" data-dim="${safeDim}"`, 'تغییر قیمت کل')}
        </div>`;
    }

    function selectedArea() {
        return dimensionArea($('#set-price-dimension').val());
    }

    function updateTotalFromMeter() {
        if (priceSyncLock) return;
        const area = selectedArea();
        const meter = parsePriceNumber($('#set-price-meter').val());
        priceSyncLock = true;
        if (Number.isFinite(area) && area > 0 && Number.isFinite(meter)) {
            $('#set-price-total').val(formatPriceNumber(meter * area)).prop('disabled', false);
        } else if (!Number.isFinite(area) || area <= 0) {
            $('#set-price-total').val('').prop('disabled', true);
        }
        priceSyncLock = false;
    }

    function updateMeterFromTotal() {
        if (priceSyncLock) return;
        const area = selectedArea();
        const total = parsePriceNumber($('#set-price-total').val());
        priceSyncLock = true;
        if (Number.isFinite(area) && area > 0 && Number.isFinite(total)) {
            $('#set-price-meter').val(formatPriceNumber(total / area));
        }
        priceSyncLock = false;
    }

    function openPriceModal(type, id, options = {}) {
        const item = findItem(type, id);
        if (!item) {
            UI.showError('مورد انتخاب‌شده یافت نشد');
            return;
        }

        const dims = itemDimensions(item);
        const preferredDim = options.dimension && dims.includes(options.dimension) ? options.dimension : dims[0];
        const meter = item.has_no_price ? NaN : Number(item.price_per_meter);

        $('#set-price-id').val(item.id);
        $('#set-price-type').val(type);
        $('#set-price-item-name').text(
            (type === 'collection' ? 'کلکسیون: ' : 'محصول: ') + (item.name || '')
        );
        $('#set-price-meter').val(Number.isFinite(meter) && meter > 0 ? formatPriceNumber(meter) : '');

        const $dimSelect = $('#set-price-dimension').empty();
        if (dims.length) {
            dims.forEach(dim => {
                $dimSelect.append(`<option value="${UI.escapeHtml(dim)}">${UI.escapeHtml(dim)}</option>`);
            });
            $dimSelect.val(preferredDim);
            $('#set-price-dim-wrapper').show();
            $('#set-price-total').prop('disabled', false);
        } else {
            $('#set-price-dim-wrapper').hide();
            $('#set-price-total').val('').prop('disabled', true);
        }

        updateTotalFromMeter();
        $('#setPriceModal').modal('show');

        const focusTotal = options.focus === 'total' && !$('#set-price-total').prop('disabled');
        setTimeout(() => {
            const $field = focusTotal ? $('#set-price-total') : $('#set-price-meter');
            $field.trigger('focus').trigger('select');
        }, 300);
    }

    async function saveSetPrice() {
        const id = parseInt($('#set-price-id').val(), 10);
        const type = $('#set-price-type').val();
        let meter = parsePriceNumber($('#set-price-meter').val());
        const total = parsePriceNumber($('#set-price-total').val());
        const area = selectedArea();

        if (!Number.isFinite(meter) && Number.isFinite(total) && Number.isFinite(area) && area > 0) {
            meter = total / area;
            $('#set-price-meter').val(formatPriceNumber(meter));
        }

        if (!Number.isFinite(meter) || meter < 0) {
            UI.showError('لطفاً قیمت هر متر یا قیمت کل را وارد کنید');
            $('#set-price-meter').trigger('focus');
            return;
        }

        const price = Math.round(meter);
        $('#apply-set-price').prop('disabled', true);
        UI.showLoader();
        const result = await Api.call('set_price', { id, type, price });
        UI.hideLoader();
        $('#apply-set-price').prop('disabled', false);

        if (result?.success) {
            UI.showSuccess(result.message || 'قیمت با موفقیت بروزرسانی شد');
            $('#setPriceModal').modal('hide');
            await load();
        }
    }

    function renderPriceList() {
        const container = $('#price-list-container').empty();
        if (!priceListData || (!priceListData.collections?.length && !priceListData.products?.length)) {
            container.html('<p class="text-center p-4">هیچ محصول یا کلکسیونی برای نمایش لیست قیمت یافت نشد.</p>');
            return;
        }

        const { collections, collection_dimensions, products, last_updated } = priceListData;

        if (last_updated) {
            const date = new Date(last_updated);
            const options = { year: 'numeric', month: 'long', day: 'numeric' };
            const dateStr = date.toLocaleDateString('fa-IR', options);
            const timeStr = date.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
            $('#price-list-last-updated').html(`<i class="bi bi-clock-history me-1"></i>آخرین بروزرسانی: ${dateStr} - ${timeStr}`);
        } else {
            $('#price-list-last-updated').text('');
        }

        let finalHtml = '';

        if (collections && collections.length > 0) {
            finalHtml += '<h5>لیست قیمت کلکسیون‌ها</h5>';
            let tableHtml = `<div class="table-responsive"><table class="table table-bordered table-hover text-center" id="price-list-collections-table">
                <thead class="table-light">
                    <tr>
                        <th class="text-end">نام کلکسیون</th>
                         <th>قیمت هر متر مربع</th>
                        ${collection_dimensions.map(dim => `<th>${UI.escapeHtml(dim)}</th>`).join('')}
                    </tr>
                </thead>
                <tbody>`;

            collections.forEach(collection => {
                const noPriceClass = collection.has_no_price ? 'table-danger' : '';
                const dimCells = collection_dimensions.map(dim => {
                    const hasPrice = collection.calculated_prices[dim] !== null && !collection.has_no_price;
                    const priceText = hasPrice ? UI.formatCurrency(collection.calculated_prices[dim]) : '-';
                    if (!hasPrice || !canEditPrices()) {
                        return `<td>${priceText}</td>`;
                    }
                    return `<td class="align-middle"><div class="d-flex align-items-center justify-content-center gap-1">
                        <span>${priceText}</span>
                        ${editButtonHtml('price-edit-btn', `data-id="${collection.id}" data-type="collection" data-focus="total" data-dim="${UI.escapeHtml(dim)}"`, 'تغییر قیمت کل')}
                    </div></td>`;
                }).join('');

                tableHtml += `<tr class="${noPriceClass}">
                    <td class="text-end fw-bold">${UI.escapeHtml(collection.name)} <span class="badge bg-${collection.type === 'machine' ? 'info' : 'success'}">${collection.type === 'machine' ? 'ماشینی' : 'دستبافت'}</span></td>
                    ${renderMeterPriceCell(collection, 'collection')}
                    ${dimCells}
                </tr>`;
            });

            tableHtml += '</tbody></table></div>';
            finalHtml += tableHtml;
        }

        function productMinArea(product) {
            const dims = Object.keys(product.calculated_prices || {}).filter(d => product.calculated_prices[d] !== null);
            if (!dims.length) return NaN;
            let min = Infinity;
            for (const d of dims) {
                const a = dimensionArea(d);
                if (!isNaN(a) && a < min) min = a;
            }
            return min === Infinity ? NaN : min;
        }

        if (products && products.length > 0) {
            finalHtml += '<h5 class="mt-4">لیست قیمت محصولات تکی (با موجودی انبار)</h5>';

            const sortedProducts = [...products].sort((a, b) => {
                const aMin = productMinArea(a);
                const bMin = productMinArea(b);
                if (isNaN(aMin) && isNaN(bMin)) return a.name.localeCompare(b.name);
                if (isNaN(aMin)) return 1;
                if (isNaN(bMin)) return -1;
                if (aMin !== bMin) return aMin - bMin;
                return a.name.localeCompare(b.name);
            });

            const perColumn = Math.ceil(sortedProducts.length / 3);
            finalHtml += '<div id="single-products-price-list-row" class="row g-3">';

            for (let i = 0; i < 3; i++) {
                const start = i * perColumn;
                const end = start + perColumn;
                const colProducts = sortedProducts.slice(start, end);
                if (colProducts.length === 0) continue;

                finalHtml += '<div class="col-12 col-md-6 col-lg-4">';
                finalHtml += `<div class="table-responsive"><table class="table table-bordered table-hover text-center price-list-products-table">
                    <thead class="table-light">
                        <tr>
                            <th class="text-end">نام محصول</th>
                            <th>قیمت هر متر مربع</th>
                            <th>ابعاد</th>
                            <th>قیمت</th>
                        </tr>
                    </thead>
                    <tbody>`;

                colProducts.forEach(product => {
                    const dims = Object.keys(product.calculated_prices || {}).filter(d => product.calculated_prices[d] !== null);
                    const dimsSorted = dims.sort((a, b) => {
                        const da = dimensionArea(a);
                        const db = dimensionArea(b);
                        if (isNaN(da) && isNaN(db)) return a.localeCompare(b);
                        if (isNaN(da)) return 1;
                        if (isNaN(db)) return -1;
                        if (da !== db) return da - db;
                        return a.localeCompare(b);
                    });
                    const dimsHtml = dimsSorted.length ? dimsSorted.map(d => `<div>${UI.escapeHtml(d)}</div>`).join('') : '-';
                    const pricesHtml = dimsSorted.length
                        ? dimsSorted.map(d => {
                            const priceText = !product.has_no_price && product.calculated_prices[d] != null
                                ? UI.formatCurrency(product.calculated_prices[d])
                                : '-';
                            if (priceText === '-' || !canEditPrices()) {
                                return `<div>${priceText}</div>`;
                            }
                            return renderStackedDimPrice(product, 'product', d, priceText);
                        }).join('')
                        : '-';
                    const noPriceClass = product.has_no_price ? 'table-danger' : '';
                    finalHtml += `<tr class="${noPriceClass}">
                        <td class="text-end fw-bold">${UI.escapeHtml(product.name)}</td>
                        ${renderMeterPriceCell(product, 'product')}
                        <td class="text-end">${dimsHtml}</td>
                        <td>${pricesHtml}</td>
                    </tr>`;
                });

                finalHtml += '</tbody></table></div></div>';
            }

            finalHtml += '</div>';
        }

        container.html(finalHtml);
    }

    async function load() {
        UI.showLoader();
        const data = await Api.call('get_price_list_data');
        if (data) {
            priceListData = data;
            renderPriceList();
        }
        UI.hideLoader();
    }

    function print() {
        const collectionsTableHtml = $('#price-list-collections-table').length ? $('#price-list-collections-table').prop('outerHTML') : '';
        const productsRowHtml = $('#single-products-price-list-row').length ? $('#single-products-price-list-row').prop('outerHTML') : '';
        const entityName = App.getAppState().businessEntities.find(e => e.id == App.getAppState().currentEntityId)?.name || 'مجموعه';

        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            UI.showError('پنجره چاپ مسدود شد. لطفاً popup blocker مرورگر را غیرفعال کنید.');
            return;
        }
        printWindow.document.write(`
        <html><head><title>لیست قیمت - ${entityName}</title>
            <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
            <link rel="stylesheet" href="assets/css/vazirmatn-font-face.css">
            <style>
                @page { 
                    size: A4 landscape;
                    margin: 10mm;
                }
                body { 
                    font-family: Vazirmatn, sans-serif; 
                    direction: rtl; 
                    background-color: #fff !important; 
                    -webkit-print-color-adjust: exact; 
                }
                 @media print { .no-print { display: none !important; } }
                .no-print { display: none !important; }
                table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 2rem; }
                th, td { border: 1px solid #dee2e6; padding: 0.4rem; text-align: center; vertical-align: middle; }
                th { background-color: #f8f9fa !important; }
                 h5 { font-size: 1.2rem; }
                .text-end { text-align: right !important; }
                .badge { font-size: 10px; }
            </style></head><body>
            <div class="container-fluid mt-4">
                <h2 class="text-center mb-4">لیست قیمت فروش محصولات - ${entityName}</h2>
                 ${collectionsTableHtml}
                ${productsRowHtml}
            </div>
        </body></html>`);
        printWindow.document.close();
        printWindow.focus();
        setTimeout(() => { printWindow.print(); printWindow.close(); }, 500);
    }

    function populateBulkPriceItems(type) {
        const container = $('#bulk-price-items-list').empty();
        const items = type === 'collection' ? priceListData.collections : priceListData.products;
        
        if (!items || items.length === 0) {
            container.html('<p class="text-muted small">موردی یافت نشد</p>');
            return;
        }
        
        items.forEach(item => {
            const priceText = item.has_no_price ? 'بدون قیمت' : UI.formatCurrency(item.price_per_meter);
            const priceClass = item.has_no_price ? 'text-danger' : 'text-muted';
            container.append(`
                <div class="form-check">
                    <input class="form-check-input bulk-price-item" type="checkbox" value="${item.id}" id="bulk-item-${item.id}" ${item.has_no_price ? 'disabled' : ''}>
                    <label class="form-check-label d-flex justify-content-between w-100" for="bulk-item-${item.id}">
                        <span>${UI.escapeHtml(item.name)}</span>
                        <small class="${priceClass}">${priceText}</small>
                    </label>
                </div>
            `);
        });
    }

    async function applyBulkPriceUpdate() {
        const type = $('#bulk-price-type').val();
        const percentage = parseFloat($('#bulk-price-percentage').val());
        const selectedIds = [];
        
        $('.bulk-price-item:checked').each(function() {
            selectedIds.push(parseInt($(this).val()));
        });
        
        if (selectedIds.length === 0) {
            UI.showError('لطفاً حداقل یک مورد انتخاب کنید');
            return;
        }
        
        if (!percentage || percentage === 0) {
            UI.showError('لطفاً درصد تغییر قیمت را وارد کنید');
            return;
        }
        
        const confirmMsg = percentage > 0 
            ? `آیا از افزایش ${percentage}% قیمت ${selectedIds.length} مورد انتخابی اطمینان دارید؟`
            : `آیا از کاهش ${Math.abs(percentage)}% قیمت ${selectedIds.length} مورد انتخابی اطمینان دارید؟`;
        
        if (!await UI.confirmText(confirmMsg)) return;
        
        UI.showLoader();
        const result = await Api.call('update_prices_by_percentage', {
            ids: selectedIds,
            type: type,
            percentage: percentage
        });
        UI.hideLoader();
        
        if (result?.success) {
            UI.showSuccess(result.message || 'قیمت‌ها با موفقیت بروزرسانی شدند');
            $('#bulkPriceUpdateModal').modal('hide');
            await load();
        } else {
            UI.showError(result?.error || 'خطا در بروزرسانی قیمت‌ها');
        }
    }

    function init() {
        $('#print-price-list-btn').on('click', print);
        
        $('#bulk-price-update-btn').on('click', function() {
            $('#bulk-price-percentage').val('');
            $('#bulk-price-select-all').prop('checked', false);
            populateBulkPriceItems($('#bulk-price-type').val());
            $('#bulkPriceUpdateModal').modal('show');
        });
        
        $('#bulk-price-type').on('change', function() {
            $('#bulk-price-select-all').prop('checked', false);
            populateBulkPriceItems($(this).val());
        });
        
        $('#bulk-price-select-all').on('change', function() {
            $('.bulk-price-item:not(:disabled)').prop('checked', $(this).is(':checked'));
        });
        
        $('#apply-bulk-price-update').on('click', applyBulkPriceUpdate);

        $('#price-list-container').on('click', '.price-edit-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $btn = $(this);
            openPriceModal($btn.data('type'), $btn.data('id'), {
                focus: $btn.data('focus') || 'meter',
                dimension: $btn.attr('data-dim') || ''
            });
        });

        $('#set-price-meter').on('input', updateTotalFromMeter);
        $('#set-price-total').on('input', updateMeterFromTotal);
        $('#set-price-dimension').on('change', updateTotalFromMeter);
        $('#apply-set-price').on('click', saveSetPrice);

        $('#set-price-meter, #set-price-total').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveSetPrice();
            }
        });
    }

    return {
        init,
        load,
    };
})();
