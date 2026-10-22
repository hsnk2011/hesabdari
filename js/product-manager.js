// /js/product-manager.js

const ProductManager = (function () {
    const config = {
        tableName: 'products',
        apiName: 'product',
        nameFa: 'محصول',
        modalId: '#productModal',
        formId: '#product-form',
    };
    let productsCacheForModal = [];
    let currentViewMode = 'table'; // 'table' or 'card'
    let pendingTransfer = {
        itemIds: [],
        productIds: [],
        fromWarehouseId: null,
        moveAll: false,
        refresh: 'product'
    };
    let warehouseStockTimer = null;
    let productSizeTimer = null;
    let warehouseSizeTimer = null;

    /**
     * عدد متراژ از ورودی کاربر (ارقام فارسی/انگلیسی، جداکننده هزارگان)
     * خالی یا نامعتبر → '' (بدون فیلتر)
     */
    function parseAreaNumber(raw) {
        if (raw === null || raw === undefined) return '';
        let s = String(raw).trim();
        if (!s) return '';
        try {
            if (typeof UI !== 'undefined' && UI.toEnglishDigits) s = UI.toEnglishDigits(s);
        } catch (e) {}
        s = s.replace(/,/g, '').replace(/٬/g, '').replace(/٫/g, '.');
        const n = parseFloat(s);
        return (Number.isFinite(n) && n > 0) ? String(Math.round(n * 100) / 100) : '';
    }

    /** تبدیل مقدار select پیش‌فرض به بازه [min, max] — رشته خالی یعنی بدون حد */
    function sizePresetToRange(preset) {
        if (!preset || preset === 'custom' || preset === 'kenareh') return null;
        const parts = String(preset).split('-');
        if (parts.length !== 2) return null;
        const min = parts[0] === '' ? '' : parseAreaNumber(parts[0]);
        const max = parts[1] === '' ? '' : parseAreaNumber(parts[1]);
        if (min === '' && max === '') return null;
        return { min, max };
    }

    /** تشخیص مقدار select متناظر با بازه فعلی (برای بازیابی حالت) */
    function rangeToPreset(min, max, shapeFilter = '') {
        if (shapeFilter === 'kenareh') return 'kenareh';
        const key = `${min || 0}-${max || ''}`;
        const known = ['0-2', '0-3', '0-4', '0-6', '2-4', '4-6', '6-9', '6-12', '9-12', '12-'];
        if (!min && !max) return '';
        if (known.includes(key)) return key;
        return 'custom';
    }

    function populateCollectionFilter() {
        const select = $('#product-collection-filter');
        if (select.length === 0) return;
        const currentValue = select.val();
        select.empty().append('<option value="">همه کلکسیون‌ها</option>');
        const collections = App.getCache().collections || [];
        collections.forEach(c => {
            select.append(`<option value="${c.id}">${c.name}</option>`);
        });
        select.val(currentValue);
    }

    async function populateWarehouseFilter() {
        const select = $('#product-warehouse-filter');
        if (select.length === 0) return;
        const stateWarehouse = AppConfig.TABLE_STATES.products?.warehouseId || '';
        const currentValue = stateWarehouse || select.val() || '';
        select.empty()
            .append('<option value="">همه انبارها</option>')
            .append('<option value="__no_warehouse__">بدون انبار</option>');
        try {
            const result = await Api.call('get_warehouses');
            const warehouses = result?.warehouses || [];
            warehouses.forEach(w => {
                select.append(`<option value="${w.id}">${w.name}</option>`);
            });
        } catch (e) { console.error('Error loading warehouses for filter:', e); }
        select.val(currentValue);
    }

    /**
     * تولید HTML نمایش ابعاد موجودی
     */
    function renderStockDimensions(stock) {
        if (!stock || stock.length === 0) {
            return '<span class="text-muted">ناموجود</span>';
        }
        
        // گروه‌بندی بر اساس ابعاد + تشخیص سفارش بودن
        const dimensionsMap = {};
        stock.forEach(s => {
            const dim = s.dimensions || 'بدون ابعاد';
            const qty = parseInt(s.total_quantity) || 0;
            const orderQty = parseInt(s.order_quantity) || 0;
            if (qty > 0) {
                if (!dimensionsMap[dim]) {
                    dimensionsMap[dim] = { qty: 0, orderQty: 0 };
                }
                dimensionsMap[dim].qty += qty;
                dimensionsMap[dim].orderQty += orderQty;
            }
        });
        
        const entries = Object.entries(dimensionsMap);
        if (entries.length === 0) {
            return '<span class="text-muted">ناموجود</span>';
        }
        
        // نمایش حداکثر 3 ابعاد
        const displayItems = entries.slice(0, 3);
        const remaining = entries.length - 3;
        
        let html = displayItems.map(([dim, info]) => {
            const isOrder = info.orderQty > 0;
            const badgeClass = isOrder ? 'bg-primary' : 'bg-light text-dark border';
            const qtyClass = isOrder ? 'text-white-50' : 'text-success';
            return `<span class="badge ${badgeClass} me-1 mb-1">${UI.escapeHtml(dim)} <small class="${qtyClass}">(${info.qty})</small></span>`;
        }).join('');
        
        if (remaining > 0) {
            html += `<span class="badge bg-secondary">${remaining}+ بیشتر</span>`;
        }
        
        return html;
    }

    function renderTable(data) {
        productsCacheForModal = data || [];
        
        if (currentViewMode === 'card') {
            renderCardView(data);
            return;
        }
        
        const body = $('#products-table-body').empty();
        if (!data || !data.length) return body.html('<tr><td colspan="8" class="text-center text-muted py-4">محصولی یافت نشد.</td></tr>');

        data.forEach(p => {
            const fullImage = p.primary_full_image || p.primary_image;
            const imageHtml = p.primary_image
                ? `<img src="${p.primary_image}" data-full="${fullImage || p.primary_image}" data-title="${UI.escapeHtml(p.name || 'تصویر فرش')}" class="rounded product-image-preview" loading="lazy" decoding="async" style="width: 40px; height: 40px; object-fit: cover; cursor: zoom-in;" alt="${UI.escapeHtml(p.name)}" title="برای مشاهده تصویر اصلی کلیک کنید">`
                : `<div class="bg-light rounded d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;"><i class="bi bi-image text-muted"></i></div>`;
            
            const collectionBadge = p.collectionName
                ? `<span class="badge ${p.collectionType === 'machine' ? 'bg-info' : 'bg-warning text-dark'}">${p.collectionName}</span>`
                : (p.price !== null ? `<span class="badge bg-secondary">${UI.formatCurrency(p.price)}</span>` : '-');

            // نمایش انبارها
            let warehousesHtml = '-';
            if (p.warehouses && p.warehouses.length > 0) {
                warehousesHtml = p.warehouses.map(w => 
                    `<span class="badge ${w.type === 'order' ? 'bg-primary' : 'bg-light text-dark border'} me-1">${UI.escapeHtml(w.name)}${w.type === 'order' ? ' · سفارش' : ''} <small>(${w.count})</small></span>`
                ).join('');
            }

            const stockHtml = renderStockDimensions(p.stock);
            
            const descriptionShort = p.description ? (p.description.length > 30 ? p.description.substring(0, 30) + '...' : p.description) : '-';

            // محاسبه تعداد موجودی
            let totalQty = 0;
            if (p.stock && p.stock.length > 0) {
                p.stock.forEach(s => { totalQty += parseInt(s.total_quantity) || 0; });
            }

            const row = $(`<tr>
                <td><input type="checkbox" class="product-select-checkbox" value="${p.id}" data-name="${p.name}" data-stock="${totalQty}"></td>
                <td>${imageHtml}</td>
                <td><strong>${p.name}</strong></td>
                <td>${collectionBadge}</td>
                <td style="max-width: 180px;">${warehousesHtml}</td>
                <td style="max-width: 200px;">${stockHtml}</td>
                <td title="${p.description || ''}">${descriptionShort}</td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-info btn-print-barcode" data-id="${p.id}" title="مدیریت انبار"><i class="bi bi-boxes"></i></button>
                        <button class="btn btn-outline-warning btn-edit" data-type="product" title="ویرایش"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-outline-danger btn-delete" data-type="product" data-id="${p.id}" title="حذف"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>`);
            row.find('.btn-edit').data('entity', p);
            body.append(row);
        });
        updateBulkPrintButton();
        App.applyPermissions();
        ensureListThumbs(data);
    }

    /**
     * نمایش کارتی محصولات
     */
    function renderCardView(data) {
        const container = $('#products-card-view').empty();
        if (!data || !data.length) {
            container.html('<div class="col-12 text-center text-muted py-5">محصولی یافت نشد.</div>');
            return;
        }

        data.forEach(p => {
            let totalQty = 0;
            if (p.stock && p.stock.length > 0) {
                p.stock.forEach(s => { totalQty += parseInt(s.total_quantity) || 0; });
            }
            
            const stockBadgeClass = totalQty > 0 ? 'bg-success' : 'bg-danger';
            const placeholderSvg = `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='150'%3E%3Crect fill='%23f8f9fa' width='200' height='150'/%3E%3Ctext x='50%25' y='50%25' font-family='Arial' font-size='14' fill='%236c757d' text-anchor='middle' dy='.3em'%3Eبدون تصویر%3C/text%3E%3C/svg%3E`;
            const imageUrl = p.primary_image || placeholderSvg;
            const fullImageUrl = p.primary_full_image || p.primary_image || '';
            const collectionType = p.collectionType === 'machine' ? 'ماشینی' : (p.collectionType === 'handmade' ? 'دستبافت' : '');
            
            const card = $(`
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card h-100" data-product-id="${p.id}">
                        <div class="position-relative">
                            <div class="position-absolute top-0 start-0 m-2" style="z-index:1;">
                                <input type="checkbox" class="product-select-checkbox form-check-input" value="${p.id}" data-name="${p.name}" data-stock="${totalQty}" style="width:20px;height:20px;">
                            </div>
                            <img src="${imageUrl}" class="card-img-top product-image-preview" ${fullImageUrl ? `data-full="${fullImageUrl}" data-title="${p.name}"` : ''} loading="lazy" decoding="async" style="height: 140px; object-fit: cover; ${fullImageUrl ? 'cursor: zoom-in;' : ''}" alt="${p.name}" title="${fullImageUrl ? 'برای مشاهده تصویر اصلی کلیک کنید' : ''}" onerror="this.src='${placeholderSvg}'">
                            <span class="badge ${stockBadgeClass} position-absolute top-0 end-0 m-2">${totalQty} ${p.unit || 'عدد'}</span>
                        </div>
                        <div class="card-body p-2">
                            <h6 class="card-title mb-1 text-truncate" title="${p.name}">${p.name}</h6>
                            ${p.collectionName ? `<small class="text-muted">${p.collectionName} ${collectionType ? `(${collectionType})` : ''}</small>` : ''}
                        </div>
                        <div class="card-footer p-2 bg-transparent border-top-0">
                            <div class="btn-group btn-group-sm w-100">
                                <button class="btn btn-outline-info btn-print-barcode" data-id="${p.id}" title="موجودی"><i class="bi bi-boxes"></i></button>
                                <button class="btn btn-outline-warning btn-edit" data-type="product" title="ویرایش"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-outline-danger btn-delete" data-type="product" data-id="${p.id}" title="حذف"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            `);
            card.find('.btn-edit').data('entity', p);
            container.append(card);
        });
        updateBulkPrintButton();
        App.applyPermissions();
        ensureListThumbs(data);
    }

    let thumbEnsureInFlight = false;
    const ensuredThumbProductIds = new Set();
    async function ensureListThumbs(data) {
        if (thumbEnsureInFlight || !data || !data.length) return;
        const ids = data.map(p => p.id).filter(id => id && !ensuredThumbProductIds.has(Number(id)));
        if (!ids.length) return;
        ids.forEach(id => ensuredThumbProductIds.add(Number(id)));
        thumbEnsureInFlight = true;
        try {
            const result = await Api.call('ensure_product_list_thumbs', { product_ids: ids });
            if (result?.updated > 0 && typeof App.getManager === 'function') {
                const mgr = App.getManager('products');
                if (mgr && typeof mgr.load === 'function') {
                    mgr.load();
                }
            }
        } catch (e) {
            // نادیده — لیست با فایل اصلی هم کار می‌کند
        } finally {
            thumbEnsureInFlight = false;
        }
    }

    async function renderSerializedItems(productId) {
        const container = $('#serialized-items-container').empty();
        const items = await Api.call('get_serialized_items', { productId });

        setPriceDimensionFromInventory(Array.isArray(items) ? items : []);

        if (!items || items.length === 0) {
            container.html('<p class="text-center text-muted p-3">هیچ آیتمی برای این محصول در انبار ثبت نشده است.</p>');
            return;
        }

        // دکمه انتقال دسته‌ای
        const transferBtn = $('<div class="mb-2"><button class="btn btn-sm btn-outline-primary" id="btn-transfer-selected" disabled><i class="bi bi-arrow-left-right me-1"></i>انتقال به انبار دیگر</button></div>');
        container.append(transferBtn);

        const table = $('<table class="table table-sm table-hover"><thead><tr><th><input type="checkbox" id="select-all-items-print"></th><th>شناسه</th><th>ابعاد</th><th>انبار</th><th>وضعیت</th><th>تاریخ ورود</th><th>عملیات</th></tr></thead><tbody></tbody></table>');
        const tbody = table.find('tbody');

        items.forEach(item => {
            const statusMap = { 
                'in_stock': 'موجود', 
                'sold': 'فروخته شده', 
                'consignment_out': 'امانی',
                'returned': 'موجود (مرجوع شده)',
                'returned_to_supplier': 'مرجوع به تامین‌کننده',
                'reserved': 'رزرو شده',
                'damaged': 'آسیب دیده'
            };
            const statusBadge = item.status === 'in_stock' ? 'bg-success' 
                              : item.status === 'returned' ? 'bg-info'
                              : item.status === 'returned_to_supplier' ? 'bg-dark'
                              : item.status === 'reserved' ? 'bg-warning text-dark'
                              : item.status === 'damaged' ? 'bg-danger'
                              : 'bg-secondary';

            let actions = `<button class="btn btn-sm btn-outline-warning btn-edit-inventory-item py-0 px-1" data-item-id="${item.id}" data-dimensions="${item.dimensions}" data-serial-id="${item.serial_id || ''}" data-warehouse-id="${item.warehouse_id || ''}" title="ویرایش"><i class="bi bi-pencil"></i></button>`;
            if (item.status === 'in_stock' || item.status === 'returned') {
                actions += ` <button class="btn btn-sm btn-outline-danger btn-delete-inventory-item py-0 px-1" data-item-id="${item.id}" title="حذف آیتم"><i class="bi bi-trash"></i></button>`;
            }

            const warehouseName = item.warehouse_name || '-';
            const canSelect = (item.status === 'in_stock' || item.status === 'returned');

            const row = $(`<tr data-inventory-id="${item.id}" data-serial-label="${item.serial_id || ''}">
                <td><input type="checkbox" class="print-item-checkbox" value="${item.id}" data-serial-id="${item.serial_id || ''}" ${!canSelect ? 'disabled' : ''}></td>
                <td title="شناسه انبار (serial_id)">${item.serial_id || item.id}</td>
                <td>${item.dimensions}</td>
                <td><span class="badge bg-light text-dark">${warehouseName}</span></td>
                <td><span class="badge ${statusBadge}">${statusMap[item.status] || item.status}</span></td>
                <td>${UI.gregorianToPersian(item.entry_date)}</td>
                <td>${actions}</td>
            </tr>`);
            tbody.append(row);
        });
        container.append(table);

        // فعال/غیرفعال کردن دکمه انتقال
        container.off('change', '.print-item-checkbox').on('change', '.print-item-checkbox', function() {
            const checkedCount = container.find('.print-item-checkbox:checked').length;
            $('#btn-transfer-selected').prop('disabled', checkedCount === 0);
        });
    }

    /**
     * بارگذاری و نمایش تصاویر محصول
     */
    async function loadProductImages(productId) {
        const gallery = $('#product-images-gallery').empty();
        
        if (!productId) {
            gallery.html('<p class="text-center text-muted col-12">برای آپلود تصویر، ابتدا محصول را ذخیره کنید.</p>');
            return;
        }
        
        try {
            const result = await Api.call('get_product_images', { product_id: productId });
            const images = result.images || [];
            
            if (images.length === 0) {
                gallery.html('<p class="text-center text-muted col-12">تصویری برای این محصول آپلود نشده است.</p>');
                return;
            }
            
            images.forEach(img => {
                const isPrimary = img.is_primary == 1;
                const thumbSrc = img.display_path || img.thumb_path || img.file_path;
                const fullSrc = img.file_path || thumbSrc;
                const card = $(`
                    <div class="col-md-3 col-sm-4 col-6">
                        <div class="card ${isPrimary ? 'border-primary' : ''}">
                            <img src="${thumbSrc}" data-full="${fullSrc}" data-title="تصویر محصول" class="card-img-top product-image-preview" loading="lazy" decoding="async" style="height: 120px; object-fit: cover; cursor: zoom-in;" alt="" title="برای مشاهده تصویر اصلی کلیک کنید">
                            <div class="card-body p-2 text-center">
                                ${isPrimary ? '<span class="badge bg-primary mb-1">تصویر اصلی</span>' : ''}
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-secondary btn-edit-image" data-image-id="${img.id}" data-file-path="${fullSrc}" title="ویرایش / حذف پس‌زمینه"><i class="bi bi-pencil-square"></i></button>
                                    ${!isPrimary ? `<button class="btn btn-outline-primary btn-set-primary" data-image-id="${img.id}" title="تنظیم به عنوان تصویر اصلی"><i class="bi bi-star"></i></button>` : ''}
                                    <button class="btn btn-outline-danger btn-delete-image" data-image-id="${img.id}" title="حذف"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
                gallery.append(card);
            });
        } catch (e) {
            gallery.html('<p class="text-center text-danger col-12">خطا در بارگذاری تصاویر.</p>');
        }
    }

    let priceSyncLock = false;

    function parsePriceNumber(raw) {
        const n = parseFloat(String(raw ?? '').replace(/,/g, '').trim());
        return Number.isFinite(n) ? n : NaN;
    }

    function formatPriceNumber(n) {
        if (!Number.isFinite(n)) return '';
        return Math.round(n).toLocaleString('en-US');
    }

    /** Area in m² from dimension string (same rules as price list). */
    function calcArea(dimension) {
        if (!dimension) return NaN;
        let d = String(dimension).trim();
        const isMeterUnit = /(^|\s)(m|متر)(\s|$)/i.test(d);
        const isCentimeterUnit = /(^|\s)(cm|سانتی|سانت)(\s|$)/i.test(d);
        if (isMeterUnit || isCentimeterUnit) {
            // Explicit unit suffix: honor it directly.
            d = d.replace(/[×*]/g, 'x').replace(/\s+/g, '');
            const parts = d.split('x');
            if (parts.length !== 2) return NaN;
            let width = parseFloat(parts[0].replace(/[^\d.]/g, ''));
            let height = parseFloat(parts[1].replace(/[^\d.]/g, ''));
            if (!(width > 0) || !(height > 0)) return NaN;
            if (isCentimeterUnit) {
                width /= 100;
                height /= 100;
            }
            return width * height;
        }
        // Shared rule: named-dimension map + per-side cm threshold from settings.
        const area = UI.dimensionArea(d.replace(/×/g, 'x'));
        return area > 0 ? area : NaN;
    }

    function getDimensionFromInventoryItems(items) {
        const counts = {};
        const stockStatuses = new Set(['in_stock', 'returned', 'reserved']);
        (items || []).forEach(item => {
            const dim = String(item.dimensions || '').trim();
            if (!dim || dim === 'بدون ابعاد') return;
            // Prefer stocked items; still count others as fallback
            const weight = stockStatuses.has(item.status) ? 2 : 1;
            counts[dim] = (counts[dim] || 0) + weight;
        });
        const unique = Object.keys(counts);
        if (!unique.length) {
            return { dimension: '', unique: [], hint: 'ابعادی در موجودی انبار ثبت نشده است.' };
        }
        unique.sort((a, b) => counts[b] - counts[a] || a.localeCompare(b, 'fa'));
        const dimension = unique[0];
        const hint = unique.length > 1
            ? `ابعاد غالب موجودی: ${dimension} — سایر ابعاد انبار: ${unique.slice(1).join('، ')}`
            : `ابعاد از موجودی انبار: ${dimension}`;
        return { dimension, unique, hint };
    }

    function setPriceDimensionFromInventory(items) {
        const { dimension, hint } = getDimensionFromInventoryItems(items);
        $('#product-price-dimension').val(dimension);
        $('#product-price-dimension-hint').text(hint || '');
        updateTotalFromMeter();
    }

    function clearPriceDimensionFromInventory(message) {
        $('#product-price-dimension').val('');
        $('#product-price-dimension-hint').text(message || 'ابتدا محصول را ذخیره و موجودی انبار ثبت کنید.');
        updateTotalFromMeter();
    }

    function updateTotalFromMeter() {
        if (priceSyncLock) return;
        const area = calcArea($('#product-price-dimension').val());
        const meter = parsePriceNumber($('#product-price').val());
        priceSyncLock = true;
        if (Number.isFinite(area) && area > 0 && Number.isFinite(meter)) {
            $('#product-price-total').val(formatPriceNumber(meter * area));
            $('#product-price-total').prop('disabled', false);
        } else if (!Number.isFinite(area) || area <= 0) {
            $('#product-price-total').val('');
            $('#product-price-total').prop('disabled', true);
        }
        priceSyncLock = false;
    }

    function updateMeterFromTotal() {
        if (priceSyncLock) return;
        const area = calcArea($('#product-price-dimension').val());
        const total = parsePriceNumber($('#product-price-total').val());
        priceSyncLock = true;
        if (Number.isFinite(area) && area > 0 && Number.isFinite(total)) {
            $('#product-price').val(formatPriceNumber(total / area));
        }
        priceSyncLock = false;
    }

    /**
     * Shows or hides the standalone price field based on the collection dropdown's value.
     */
    function updatePriceFieldVisibility() {
        const collectionId = $('#product-collection-id').val();
        const priceWrapper = $('#product-price-wrapper');
        if (collectionId && collectionId !== '') {
            priceWrapper.hide();
            $('#product-price').val('0');
            $('#product-price-total').val('');
        } else {
            priceWrapper.show();
            updateTotalFromMeter();
        }
    }

    async function prepareModal(product = null) {
        const modal = $(config.modalId);
        modal.find('form')[0].reset();
        $('#product-id').val(product ? product.id : '');

        $('#product-name').val(product ? product.name : '');
        $('#product-description').val(product ? product.description : '');
        $('#product-design-code').val(product ? product.design_code : '');
        $('#product-colorway').val(product ? product.colorway : '');
        $('#product-weight').val(product ? product.weight : '');

        clearPriceDimensionFromInventory(
            product ? 'در حال خواندن ابعاد از موجودی انبار...' : 'ابتدا محصول را ذخیره و موجودی انبار ثبت کنید.'
        );

        // کلکسیون
        const collectionSelect = $('#product-collection-id').empty().append('<option value="">-- بدون کلکسیون (محصول تکی) --</option>');
        App.getCache().collections.forEach(c => {
            collectionSelect.append(`<option value="${c.id}">${c.name} (${c.type === 'machine' ? 'ماشینی' : 'دستبافت'})</option>`);
        });

        if (product) {
            $('#product-collection-id').val(product.collection_id);
            if (product.price !== null && !product.collection_id) {
                $('#product-price').val(Number(product.price).toLocaleString('en-US'));
            } else {
                $('#product-price').val('0');
            }
        } else {
            $('#product-collection-id').val('');
            $('#product-price').val('0');
        }

        if (product) {
            await renderSerializedItems(product.id);
            await loadProductImages(product.id);
        } else {
            $('#serialized-items-container').html('<p class="text-center text-muted p-3">برای مشاهده و مدیریت آیتم‌های انبار، ابتدا محصول را ذخیره کنید.</p>');
            $('#product-images-gallery').html('<p class="text-center text-muted col-12">برای آپلود تصویر، ابتدا محصول را ذخیره کنید.</p>');
        }

        // بازگشت به تب اول
        new bootstrap.Tab(document.querySelector('#product-main-tab')).show();

        modal.modal('show');
    }

    function getFormData() {
        return {
            id: $('#product-id').val(),
            name: $('#product-name').val(),
            description: $('#product-description').val(),
            collection_id: $('#product-collection-id').val(),
            design_code: $('#product-design-code').val(),
            colorway: $('#product-colorway').val(),
            price: $('#product-price').val().replace(/,/g, ''),
            weight: $('#product-weight').val(),
            // sku removed — شناسه is per inventory item (serial_id), not per product
        };
    }

    async function prepareInventoryItemModal(productId, item = null) {
        const modal = $('#inventoryItemModal');
        UI.hideModalError('#inventoryItemModal');
        $('#inventory-item-form')[0].reset();

        // بارگذاری لیست انبارها
        const warehouseSelect = $('#inventory-item-warehouse');
        warehouseSelect.empty().append('<option value="">-- بدون انبار --</option>');
        try {
            const result = await Api.call('get_warehouses');
            const warehouses = result?.warehouses || [];
            if (warehouses.length) {
                warehouses.forEach(w => {
                    warehouseSelect.append(`<option value="${w.id}">${w.name}</option>`);
                });
            }
        } catch (e) { console.error('Error loading warehouses:', e); }

        $('#inventory-item-product-id').val(productId);
        $('#inventory-item-id').val(item ? item.id : '');
        $('#inventory-item-serial-id').val(item ? item.serial_id : '');
        $('#inventory-item-dimensions').val(item ? item.dimensions : '');
        warehouseSelect.val(item ? (item.warehouse_id || '') : '');

        if (item) {
            $('#inventoryItemModalTitle').text('ویرایش آیتم انبار');
            $('#inventory-item-count-wrapper').hide();
            $('#inventory-item-serial-wrapper').show();
        } else {
            $('#inventoryItemModalTitle').text('افزودن آیتم به انبار');
            $('#inventory-item-count-wrapper').show();
            $('#inventory-item-serial-wrapper').hide();
            $('#inventory-item-count').val(1);
        }
        modal.modal('show');
    }

    async function handleInventoryItemFormSubmit(e) {
        e.preventDefault();
        UI.hideModalError('#inventoryItemModal');
        const itemId = $('#inventory-item-id').val();
        const productId = $('#inventory-item-product-id').val();
        const warehouseId = $('#inventory-item-warehouse').val();

        let result;
        if (itemId) {
            const data = {
                itemId: itemId,
                serial_id: $('#inventory-item-serial-id').val(),
                dimensions: $('#inventory-item-dimensions').val(),
                warehouse_id: warehouseId || null
            };
            result = await Api.call('update_serialized_item', data);
        } else {
            const data = {
                productId: productId,
                dimensions: $('#inventory-item-dimensions').val(),
                count: $('#inventory-item-count').val(),
                warehouse_id: warehouseId || null
            };
            result = await Api.call('add_serialized_item', data);
        }

        if (result?.success) {
            UI.showSuccess('عملیات انبار با موفقیت انجام شد.');
            $('#inventoryItemModal').modal('hide');
            await renderSerializedItems(productId);
            App.getManager('products').load();
        } else if (result?.error) {
            UI.showModalError('#inventoryItemModal', result.error);
        }
    }

    async function fillActiveWarehouseSelect(select, placeholder) {
        select.empty().append(`<option value="">${placeholder}</option>`);
        const result = await Api.call('get_warehouses');
        const warehouses = (result?.warehouses || []).filter(w => String(w.is_active) === '1' || w.is_active == 1);
        warehouses.forEach(w => {
            select.append(`<option value="${w.id}">${UI.escapeHtml(w.name)}</option>`);
        });
        return warehouses;
    }

    /**
     * نمایش مودال انتقال آیتم‌ها
     */
    async function openTransferDialog(options) {
        pendingTransfer = {
            itemIds: options.itemIds || [],
            productIds: options.productIds || [],
            fromWarehouseId: options.fromWarehouseId ?? null,
            moveAll: !!options.moveAll,
            refresh: options.refresh || 'product'
        };
        if (!pendingTransfer.moveAll && pendingTransfer.itemIds.length === 0 && pendingTransfer.productIds.length === 0) {
            UI.showError('لطفاً حداقل یک فرش یا محصول را انتخاب کنید.');
            return;
        }

        const modal = $('#transferItemsModal');
        UI.hideModalError('#transferItemsModal');
        $('#transfer-item-ids').val(pendingTransfer.itemIds.join(','));
        $('#transfer-product-id').val(options.productId || '');
        $('#transfer-items-count').text(options.countLabel || String(pendingTransfer.itemIds.length || pendingTransfer.productIds.length));
        $('#transfer-items-hint').text(options.hint || 'فقط فرش موجود یا مرجوع‌شده منتقل می‌شود. فرش‌هایی که همین حالا در انبار مقصد هستند جابه‌جا نمی‌شوند.');

        try {
            await fillActiveWarehouseSelect($('#transfer-to-warehouse'), '-- انتخاب انبار --');
        } catch (e) { console.error('Error loading warehouses:', e); }

        modal.modal('show');
    }

    async function showTransferModal(productId) {
        const selectedIds = [];
        $('#serialized-items-container .print-item-checkbox:checked').each(function() {
            selectedIds.push($(this).val());
        });
        if (selectedIds.length === 0) {
            UI.showError('لطفاً حداقل یک آیتم را انتخاب کنید.');
            return;
        }
        await openTransferDialog({
            itemIds: selectedIds,
            productId,
            countLabel: selectedIds.length + ' فرش',
            refresh: 'product'
        });
    }

    /**
     * انتقال آیتم‌ها به انبار دیگر
     */
    async function handleTransferFormSubmit(e) {
        e.preventDefault();
        UI.hideModalError('#transferItemsModal');

        const toWarehouseId = $('#transfer-to-warehouse').val();
        const productId = $('#transfer-product-id').val();

        if (!toWarehouseId) {
            UI.showModalError('#transferItemsModal', 'لطفاً انبار مقصد را انتخاب کنید.');
            return;
        }

        const payload = {
            to_warehouse_id: toWarehouseId,
            item_ids: pendingTransfer.itemIds,
            product_ids: pendingTransfer.productIds
        };
        if (pendingTransfer.fromWarehouseId) {
            payload.from_warehouse_id = pendingTransfer.fromWarehouseId;
        }
        if (pendingTransfer.moveAll) {
            payload.move_all = 1;
        }

        const result = await Api.call('transfer_inventory_items', payload);

        if (result?.success) {
            if (!result.transferred) {
                UI.showError('فرش قابل‌انتقالی پیدا نشد. فقط موجودی یا مرجوع‌شده منتقل می‌شود.');
                return;
            }
            UI.showSuccess(`${result.transferred} فرش منتقل شد.`);
            $('#transferItemsModal').modal('hide');
            if (productId) {
                await renderSerializedItems(productId);
            }
            if (pendingTransfer.refresh === 'warehouse' || $('#warehousesModal').hasClass('show')) {
                await loadWarehouses();
                await loadWarehouseStock();
            }
            if (App.getManager('products')) {
                App.getManager('products').load();
            }
        } else if (result?.error) {
            UI.showModalError('#transferItemsModal', result.error);
        }
    }

    /**
     * بروزرسانی دکمه چاپ دسته‌ای لیبل بر اساس چک‌باکس‌های انتخاب‌شده
     */
    function updateBulkPrintButton() {
        const checkedCount = $('.product-select-checkbox:checked').length;
        const btn = $('#bulk-print-labels-btn');
        const transferBtn = $('#bulk-transfer-products-btn');
        if (checkedCount > 0) {
            btn.show();
            transferBtn.show();
            $('#bulk-print-count').text(checkedCount);
            $('#bulk-transfer-count').text(checkedCount);
        } else {
            btn.hide();
            transferBtn.hide();
        }
        // بروزرسانی وضعیت select all
        const totalCheckboxes = $('.product-select-checkbox').length;
        $('#select-all-products').prop('checked', totalCheckboxes > 0 && checkedCount === totalCheckboxes);
    }

    /**
     * نمایش تصویر اصلی فرش در مودال بزرگ (لایت‌باکس)
     */
    function showProductImage(src, title) {
        if (!src || src.startsWith('data:image/svg')) return;
        const modalEl = document.getElementById('product-image-viewer-modal');
        if (!modalEl) {
            window.open(src, '_blank');
            return;
        }
        $('#product-image-viewer-title').text(title || 'تصویر فرش');
        const img = $('#product-image-viewer-img');
        img.attr('src', src).attr('alt', title || 'تصویر اصلی فرش');
        $('#product-image-viewer-open').attr('href', src);
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    function init() {
        // نمایش تصویر اصلی فرش در لایت‌باکس (لیست محصولات / موجودی انبار / گالری)
        $('body').off('click.productImagePreview').on('click.productImagePreview', '.product-image-preview[data-full]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const src = $(this).attr('data-full') || $(this).attr('src');
            const title = $(this).attr('data-title') || $(this).attr('alt') || 'تصویر فرش';
            showProductImage(src, title);
        });

        const manager = createEntityManager({
            tableName: config.tableName,
            apiName: config.apiName,
            nameFa: config.nameFa,
            addBtnId: '#add-product-btn',
            modalId: config.modalId,
            formId: config.formId,
            renderTable: renderTable,
            prepareModal: prepareModal,
            getFormData: getFormData,
            refreshCacheKeys: ['products']
        });

        const originalLoad = manager.load;
        manager.load = async function (filters = null, message = null) {
            populateCollectionFilter();
            await populateWarehouseFilter();
            await originalLoad(filters, message);
        };

        $('#product-stock-filter').val(AppConfig.TABLE_STATES.products.stockFilter || 'in_stock');

        // فیلترها
        $('#product-collection-filter, #product-stock-filter, #product-warehouse-filter').on('change', function () {
            const state = AppConfig.TABLE_STATES.products;
            state.collectionId = $('#product-collection-filter').val();
            state.stockFilter = $('#product-stock-filter').val();
            state.warehouseId = $('#product-warehouse-filter').val();
            state.currentPage = 1;
            manager.load();
        });

        // فیلتر اندازه (متراژ / کناره): بازیابی حالت قبلی
        (function restoreProductSizeFilter() {
            const state = AppConfig.TABLE_STATES.products;
            const min = state.minArea || '';
            const max = state.maxArea || '';
            $('#product-min-area').val(min);
            $('#product-max-area').val(max);
            $('#product-size-filter').val(rangeToPreset(min, max, state.shapeFilter || ''));
        })();

        function applyProductSizeFilter() {
            const state = AppConfig.TABLE_STATES.products;
            state.minArea = parseAreaNumber($('#product-min-area').val());
            state.maxArea = parseAreaNumber($('#product-max-area').val());
            state.currentPage = 1;
            manager.load();
        }

        $('#product-size-filter').on('change', function () {
            const val = $(this).val();
            const state = AppConfig.TABLE_STATES.products;
            if (val === 'kenareh') {
                $('#product-min-area').val('');
                $('#product-max-area').val('');
                state.minArea = '';
                state.maxArea = '';
                state.shapeFilter = 'kenareh';
                state.currentPage = 1;
                manager.load();
                return;
            }
            state.shapeFilter = '';
            const range = sizePresetToRange(val);
            if (range) {
                $('#product-min-area').val(range.min);
                $('#product-max-area').val(range.max);
                applyProductSizeFilter();
            } else if (val === '') {
                $('#product-min-area').val('');
                $('#product-max-area').val('');
                applyProductSizeFilter();
            }
            // custom → کاربر خودش بازه را وارد می‌کند
        });

        $('#product-min-area, #product-max-area').on('input', function () {
            clearTimeout(productSizeTimer);
            productSizeTimer = setTimeout(function () {
                const state = AppConfig.TABLE_STATES.products;
                state.shapeFilter = '';
                $('#product-size-filter').val(rangeToPreset(
                    parseAreaNumber($('#product-min-area').val()),
                    parseAreaNumber($('#product-max-area').val()),
                    ''
                ));
                applyProductSizeFilter();
            }, 600);
        });

        $('#product-size-clear').on('click', function () {
            $('#product-size-filter').val('');
            $('#product-min-area').val('');
            $('#product-max-area').val('');
            const state = AppConfig.TABLE_STATES.products;
            state.minArea = '';
            state.maxArea = '';
            state.shapeFilter = '';
            state.currentPage = 1;
            manager.load();
        });

        // تغییر نمای جدولی/کارتی
        $('#view-table-btn').on('click', function() {
            currentViewMode = 'table';
            $(this).addClass('active');
            $('#view-card-btn').removeClass('active');
            $('#products-table-view').show();
            $('#products-card-view').hide();
            manager.load();
        });

        $('#view-card-btn').on('click', function() {
            currentViewMode = 'card';
            $(this).addClass('active');
            $('#view-table-btn').removeClass('active');
            $('#products-table-view').hide();
            $('#products-card-view').show();
            manager.load();
        });

        // انتخاب همه محصولات
        $('body').on('change', '#select-all-products', function () {
            $('.product-select-checkbox').prop('checked', $(this).is(':checked'));
            updateBulkPrintButton();
        });

        // تغییر وضعیت چک‌باکس محصول
        $('body').on('change', '.product-select-checkbox', function () {
            updateBulkPrintButton();
        });

        // چاپ لیبل محصولات انتخاب‌شده
        $('#bulk-print-labels-btn').on('click', async function () {
            const selectedIds = [];
            $('.product-select-checkbox:checked').each(function () {
                selectedIds.push($(this).val());
            });
            if (selectedIds.length === 0) {
                UI.showError('هیچ محصولی انتخاب نشده است.');
                return;
            }
            UI.showLoader();
            const result = await Api.call('get_labels_for_products', { productIds: selectedIds });
            UI.hideLoader();
            if (result?.labels && result.labels.length > 0) {
                BarcodeManager.printLabelsDirectly(result.labels);
            } else {
                UI.showError(result?.error || 'هیچ آیتم موجودی برای محصولات انتخاب‌شده یافت نشد.');
            }
        });

        $('#bulk-transfer-products-btn').on('click', async function () {
            const selectedIds = [];
            $('.product-select-checkbox:checked').each(function () {
                selectedIds.push(parseInt($(this).val(), 10));
            });
            if (selectedIds.length === 0) {
                UI.showError('هیچ محصولی انتخاب نشده است.');
                return;
            }
            const fromWh = $('#product-warehouse-filter').val() || '';
            const fromHint = fromWh
                ? 'فقط فرش‌های این محصولات در انبار فیلترشده منتقل می‌شوند.'
                : 'همه فرش‌های موجود یا مرجوع‌شده این محصولات منتقل می‌شوند.';
            await openTransferDialog({
                productIds: selectedIds,
                fromWarehouseId: fromWh || null,
                countLabel: selectedIds.length + ' محصول',
                hint: fromHint,
                refresh: 'products'
            });
        });

        // چاپ لیبل همه محصولات
        $('#print-all-labels-btn').on('click', async function () {
            if (!await UI.confirmText('آیا از چاپ لیبل تمام محصولات موجود در انبار اطمینان دارید؟')) return;
            UI.showLoader();
            const result = await Api.call('get_labels_for_products', { allProducts: true });
            UI.hideLoader();
            if (result?.labels && result.labels.length > 0) {
                if (await UI.confirmText(`${result.labels.length} لیبل یافت شد. آیا ادامه می‌دهید؟`)) {
                    BarcodeManager.printLabelsDirectly(result.labels);
                }
            } else {
                UI.showError(result?.error || 'هیچ آیتم موجودی در انبار یافت نشد.');
            }
        });

        $('#productModal').on('shown.bs.modal', function () {
            updatePriceFieldVisibility();
        });

        $('#product-collection-id').on('change', updatePriceFieldVisibility);

        $('#product-price').on('input', function () {
            updateTotalFromMeter();
        });
        $('#product-price-total').on('input', function () {
            updateMeterFromTotal();
        });

        $('body').on('click', `#products-table .btn-print-barcode, #products-card-view .btn-print-barcode`, function () {
            const productId = $(this).data('id');
            const productData = productsCacheForModal.find(p => p.id == productId);
            if (productData) {
                prepareModal(productData).then(() => {
                    const inventoryTabButton = document.querySelector('#product-inventory-tab');
                    if (inventoryTabButton) {
                        new bootstrap.Tab(inventoryTabButton).show();
                    }
                });
            }
        });

        // نمای کارتی خارج از `#products-table` است و هندلرهای entity-manager به آن نمی‌رسند؛ اینجا وصل می‌کنیم
        $('body').on('click', '#products-card-view .btn-edit', function (e) {
            e.stopPropagation();
            if ($(this).data('type') !== 'product') return;
            const entity = $(this).data('entity')
                || productsCacheForModal.find(p => p.id == $(this).closest('[data-product-id]').data('product-id'));
            if (entity) {
                UI.hideModalError(config.modalId);
                manager.prepareModal(entity);
            }
        });

        $('body').on('click', '#products-card-view .btn-delete', function (e) {
            e.stopPropagation();
            if ($(this).data('type') !== 'product') return;
            const id = $(this).data('id') || $(this).closest('[data-product-id]').data('product-id');
            if (id) manager.handleDelete(id);
        });

        $('#productModal').on('click', '#print-selected-labels-btn', function () {
            const selectedIds = [];
            $('#serialized-items-container .print-item-checkbox:checked').each(function () {
                selectedIds.push($(this).val());
            });
            BarcodeManager.printBatch(selectedIds);
        });

        $('#productModal').on('change', '#select-all-items-print', function () {
            $('#serialized-items-container .print-item-checkbox').prop('checked', $(this).is(':checked'));
        });

        $('#inventory-item-form').on('submit', handleInventoryItemFormSubmit);

        $('#productModal').on('click', '#add-inventory-item-btn', async function () {
            const productId = $('#product-id').val();
            if (productId) {
                await prepareInventoryItemModal(productId, null);
            }
        });

        $('#productModal').on('click', '.btn-edit-inventory-item', async function () {
            const productId = $('#product-id').val();
            const item = {
                id: $(this).data('item-id'),
                serial_id: $(this).data('serial-id'),
                dimensions: $(this).data('dimensions'),
                warehouse_id: $(this).data('warehouse-id')
            };
            await prepareInventoryItemModal(productId, item);
        });

        // دکمه انتقال آیتم‌ها
        $('#productModal').on('click', '#btn-transfer-selected', async function () {
            const productId = $('#product-id').val();
            if (productId) {
                await showTransferModal(productId);
            }
        });

        // فرم انتقال
        $('#transfer-items-form').on('submit', handleTransferFormSubmit);

        $('#productModal').on('click', '.btn-delete-inventory-item', function () {
            const itemId = $(this).data('item-id');
            const productId = $('#product-id').val();
            UI.confirmAction('آیا از حذف این آیتم از انبار مطمئن هستید؟', async (confirmed) => {
                if (confirmed) {
                    const result = await Api.call('delete_serialized_item', { itemId });
                    if (result?.success) {
                        UI.showSuccess("آیتم با موفقیت حذف شد.");
                        await renderSerializedItems(productId);
                        App.getManager('products').load();
                    }
                }
            });
        });

        $('#save-product-btn-modal').on('click', () => $(config.formId).trigger('submit'));

        // آپلود تصویر
        $('#product-image-dropzone').on('click', function(e) {
            // فقط وقتی کلیک روی خود dropzone باشد (نه روی input)
            if (e.target.id !== 'product-image-input') {
                $('#product-image-input')[0].click();
            }
        });
        
        $('#product-image-dropzone').on('dragover', function(e) {
            e.preventDefault();
            $(this).addClass('border-primary');
        }).on('dragleave drop', function(e) {
            e.preventDefault();
            $(this).removeClass('border-primary');
        });

        $('#product-image-input').on('change', async function() {
            const productId = $('#product-id').val();
            if (!productId) {
                UI.showError('ابتدا محصول را ذخیره کنید.');
                return;
            }
            
            const files = Array.from(this.files || []);
            this.value = '';
            if (!files.length) return;

            if (window.ProductImageEditor) {
                await ProductImageEditor.openQueue(files, {
                    productId,
                    mode: 'upload',
                    onSave: async () => {
                        await loadProductImages(productId);
                    },
                });
                await loadProductImages(productId);
                return;
            }

            for (let file of files) {
                const formData = new FormData();
                formData.append('image', file);
                formData.append('product_id', productId);
                try {
                    const result = await Api.uploadFile('upload_product_image', formData);
                    if (result?.success) {
                        await loadProductImages(productId);
                        UI.showSuccess('تصویر با موفقیت آپلود شد.');
                    }
                } catch (e) {
                    UI.showError('خطا در آپلود تصویر.');
                }
            }
        });

        // حذف تصویر
        $('#product-images-gallery').on('click', '.btn-delete-image', async function() {
            const imageId = $(this).data('image-id');
            const productId = $('#product-id').val();
            
            UI.confirmAction('آیا از حذف این تصویر مطمئن هستید؟', async (confirmed) => {
                if (confirmed) {
                    const result = await Api.call('delete_product_image', { image_id: imageId });
                    if (result?.success) {
                        await loadProductImages(productId);
                        UI.showSuccess('تصویر حذف شد.');
                    }
                }
            });
        });

        // تنظیم تصویر اصلی
        $('#product-images-gallery').on('click', '.btn-set-primary', async function() {
            const imageId = $(this).data('image-id');
            const productId = $('#product-id').val();
            
            const result = await Api.call('set_primary_image', { image_id: imageId, product_id: productId });
            if (result?.success) {
                await loadProductImages(productId);
                UI.showSuccess('تصویر اصلی تنظیم شد.');
            }
        });

        // ویرایش تصویر کامل
        $('#product-images-gallery').on('click', '.btn-edit-image', async function() {
            const imageId = $(this).data('image-id');
            const filePath = $(this).data('file-path');
            const productId = $('#product-id').val();
            if (!window.ProductImageEditor) {
                UI.showError('ویرایشگر تصویر بارگذاری نشده است.');
                return;
            }
            await ProductImageEditor.open({
                src: filePath + '?t=' + Date.now(),
                imageId,
                productId,
                mode: 'edit',
                onSave: async () => {
                    await loadProductImages(productId);
                },
            });
        });

        // ========== مدیریت انبارها ==========
        $('#manage-warehouses-btn').on('click', async () => {
            await loadWarehouses();
            $('#warehousesModal').modal('show');
        });

        $('#add-warehouse-btn').on('click', () => {
            prepareWarehouseModal(null);
        });

        $('#save-warehouse-btn').on('click', async () => {
            const data = {
                id: $('#warehouse-id').val(),
                name: $('#warehouse-name').val(),
                address: $('#warehouse-address').val(),
                phone: $('#warehouse-phone').val(),
                is_default: $('#warehouse-is-default').is(':checked') ? 1 : 0,
                is_active: $('#warehouse-is-active').is(':checked') ? 1 : 0,
                show_on_dashboard: $('#warehouse-show-on-dashboard').is(':checked') ? 1 : 0,
                warehouse_type: $('#warehouse-type').val() || 'physical'
            };

            if (!data.name.trim()) {
                UI.showModalError('#warehouseFormModal', 'نام انبار الزامی است.');
                return;
            }

            const result = await Api.call('save_warehouse', data);
            if (result?.success) {
                $('#warehouseFormModal').modal('hide');
                UI.showSuccess('انبار با موفقیت ذخیره شد.');
                await loadWarehouses();
            }
        });

        $('#warehouses-table-body').on('click', '.btn-edit-warehouse', function() {
            const warehouse = $(this).data('warehouse');
            prepareWarehouseModal(warehouse);
        });

        $('#warehouses-table-body').on('click', '.btn-delete-warehouse', function() {
            const warehouseId = $(this).data('id');
            UI.confirmAction('آیا از حذف این انبار مطمئن هستید؟ موجودی آن باید خالی باشد.', async (confirmed) => {
                if (confirmed) {
                    const result = await Api.call('delete_warehouse', { id: warehouseId });
                    if (result?.success) {
                        UI.showSuccess('انبار حذف شد.');
                        await loadWarehouses();
                    } else if (result?.error) {
                        UI.showError(result.error);
                    }
                }
            });
        });

        $('#warehouses-table-body').on('click', '.btn-set-default-warehouse', async function() {
            const warehouse = $(this).data('warehouse');
            if (!warehouse) return;
            const result = await Api.call('save_warehouse', {
                id: warehouse.id,
                name: warehouse.name,
                address: warehouse.address || '',
                phone: warehouse.phone || '',
                is_default: 1,
                is_active: warehouse.is_active == 1 ? 1 : 0,
                show_on_dashboard: warehouse.show_on_dashboard == 1 ? 1 : 0,
                warehouse_type: warehouse.warehouse_type || 'physical'
            });
            if (result?.success) {
                UI.showSuccess('انبار پیش‌فرض شد.');
                await loadWarehouses();
            } else if (result?.error) {
                UI.showError(result.error);
            }
        });

        $('#warehouses-table-body').on('click', '.btn-toggle-dashboard-warehouse', async function() {
            const warehouse = $(this).data('warehouse');
            if (!warehouse) return;
            const next = warehouse.show_on_dashboard == 1 ? 0 : 1;
            const result = await Api.call('save_warehouse', {
                id: warehouse.id,
                name: warehouse.name,
                address: warehouse.address || '',
                phone: warehouse.phone || '',
                is_default: warehouse.is_default == 1 ? 1 : 0,
                is_active: warehouse.is_active == 1 ? 1 : 0,
                show_on_dashboard: next,
                warehouse_type: warehouse.warehouse_type || 'physical'
            });
            if (result?.success) {
                UI.showSuccess(next ? 'این انبار روی داشبورد نشان داده می‌شود.' : 'این انبار از داشبورد برداشته شد.');
                await loadWarehouses();
            } else if (result?.error) {
                UI.showError(result.error);
            }
        });

        $('#warehouses-table-body').on('click', '.btn-convert-order-warehouse', async function() {
            const warehouse = $(this).data('warehouse');
            if (!warehouse) return;
            const nextType = warehouse.warehouse_type === 'order' ? 'physical' : 'order';
            const message = nextType === 'order'
                ? 'این انبار به انبار سفارش تبدیل شود؟ فرش‌های فروخته‌شده تا زمان تحویل در همین انبار می‌مانند.'
                : 'این انبار به انبار فیزیکی برگردد؟';
            UI.confirmAction(message, async (confirmed) => {
                if (!confirmed) return;
                const result = await Api.call('save_warehouse', {
                    id: warehouse.id,
                    name: warehouse.name,
                    address: warehouse.address || '',
                    phone: warehouse.phone || '',
                    is_default: warehouse.is_default == 1 ? 1 : 0,
                    is_active: warehouse.is_active == 1 ? 1 : 0,
                    show_on_dashboard: nextType === 'order' ? 1 : (warehouse.show_on_dashboard == 1 ? 1 : 0),
                    warehouse_type: nextType
                });
                if (result?.success) {
                    UI.showSuccess(nextType === 'order' ? 'انبار سفارش شد و روی داشبورد نمایش داده می‌شود.' : 'انبار فیزیکی شد.');
                    await loadWarehouses();
                } else if (result?.error) {
                    UI.showError(result.error);
                }
            });
        });

        $('#warehouses-table-body').on('click', '.btn-view-warehouse-stock', async function() {
            const warehouseId = $(this).data('id');
            $('#warehouse-stock-filter').val(String(warehouseId));
            const tabBtn = document.getElementById('warehouse-tab-stock-btn');
            if (tabBtn && window.bootstrap) {
                bootstrap.Tab.getOrCreateInstance(tabBtn).show();
            } else {
                $('#warehouse-tab-stock-btn').trigger('click');
            }
            await loadWarehouseStock();
        });

        $('#warehouses-table-body').on('click', '.btn-move-warehouse-all', async function() {
            const warehouseId = $(this).data('id');
            const count = parseInt($(this).data('count'), 10) || 0;
            if (count < 1) {
                UI.showError('این انبار فرش قابل‌انتقال ندارد.');
                return;
            }
            await openTransferDialog({
                moveAll: true,
                fromWarehouseId: warehouseId,
                countLabel: count + ' فرش',
                hint: 'همه فرش‌های موجود یا مرجوع‌شده این انبار به مقصد منتقل می‌شوند.',
                refresh: 'warehouse'
            });
        });

        $('#warehouse-tab-stock-btn').on('shown.bs.tab', function () {
            loadWarehouseStock();
        });

        $('#warehouse-stock-filter').on('change', function () {
            $('#warehouse-stock-select-all').prop('checked', false);
            loadWarehouseStock();
        });

        $('#warehouse-stock-search').on('input', function () {
            clearTimeout(warehouseStockTimer);
            warehouseStockTimer = setTimeout(loadWarehouseStock, 300);
        });

        // فیلتر اندازه (متراژ / کناره) در موجودی انبار
        $('#warehouse-stock-size').on('change', function () {
            const val = $(this).val();
            if (val === 'kenareh') {
                $('#warehouse-stock-min-area').val('');
                $('#warehouse-stock-max-area').val('');
            } else {
                const range = sizePresetToRange(val);
                if (range) {
                    $('#warehouse-stock-min-area').val(range.min);
                    $('#warehouse-stock-max-area').val(range.max);
                } else if (val === '') {
                    $('#warehouse-stock-min-area').val('');
                    $('#warehouse-stock-max-area').val('');
                }
            }
            $('#warehouse-stock-select-all').prop('checked', false);
            loadWarehouseStock();
        });

        $('#warehouse-stock-min-area, #warehouse-stock-max-area').on('input', function () {
            clearTimeout(warehouseSizeTimer);
            warehouseSizeTimer = setTimeout(function () {
                $('#warehouse-stock-size').val(rangeToPreset(
                    parseAreaNumber($('#warehouse-stock-min-area').val()),
                    parseAreaNumber($('#warehouse-stock-max-area').val()),
                    ''
                ));
                $('#warehouse-stock-select-all').prop('checked', false);
                loadWarehouseStock();
            }, 600);
        });

        $('#warehouse-size-clear').on('click', function () {
            $('#warehouse-stock-size').val('');
            $('#warehouse-stock-min-area').val('');
            $('#warehouse-stock-max-area').val('');
            $('#warehouse-stock-select-all').prop('checked', false);
            loadWarehouseStock();
        });

        $('#warehouse-stock-select-all').on('change', function () {
            $('.warehouse-stock-checkbox').prop('checked', $(this).is(':checked'));
        });

        $('#warehouse-stock-body').on('change', '.warehouse-stock-checkbox', function () {
            const total = $('.warehouse-stock-checkbox').length;
            const checked = $('.warehouse-stock-checkbox:checked').length;
            $('#warehouse-stock-select-all').prop('checked', total > 0 && checked === total);
        });

        $('#warehouse-transfer-selected-btn').on('click', async function () {
            const dest = $('#warehouse-stock-dest').val();
            const itemIds = [];
            $('.warehouse-stock-checkbox:checked').each(function () {
                itemIds.push(parseInt($(this).val(), 10));
            });
            if (!itemIds.length) {
                UI.showError('حداقل یک فرش را انتخاب کنید.');
                return;
            }
            if (!dest) {
                UI.showError('انبار مقصد را انتخاب کنید.');
                return;
            }
            const result = await Api.call('transfer_inventory_items', {
                item_ids: itemIds,
                to_warehouse_id: dest
            });
            if (result?.success) {
                UI.showSuccess(`${result.transferred} فرش منتقل شد.`);
                $('#warehouse-stock-select-all').prop('checked', false);
                await loadWarehouses();
                await loadWarehouseStock();
                if (App.getManager('products')) App.getManager('products').load();
            } else if (result?.error) {
                UI.showError(result.error);
            }
        });

        $('#warehouse-move-all-btn').on('click', async function () {
            const fromWh = $('#warehouse-stock-filter').val();
            if (!fromWh) {
                UI.showError('انبار مبدأ را مشخص کنید.');
                return;
            }
            const visible = $('.warehouse-stock-checkbox').length;
            if (!visible) {
                UI.showError('فرشی در این فیلتر نیست.');
                return;
            }
            const dest = $('#warehouse-stock-dest').val();
            if (dest) {
                $('#transfer-to-warehouse').val(dest);
            }
            await openTransferDialog({
                moveAll: true,
                fromWarehouseId: fromWh,
                countLabel: (fromWh === '__no_warehouse__' ? 'فرش‌های بدون انبار' : 'همه فرش‌های این انبار'),
                hint: 'همه فرش‌های قابل‌انتقال این فیلتر منتقل می‌شوند، نه فقط ردیف‌های تیک‌خورده.',
                refresh: 'warehouse'
            });
            if (dest) {
                $('#transfer-to-warehouse').val(dest);
            }
        });

        return manager;
    }

    function fillWarehouseFilterSelect(warehouses, unassignedCount) {
        const filter = $('#warehouse-stock-filter');
        const dest = $('#warehouse-stock-dest');
        const currentFilter = filter.val();
        const currentDest = dest.val();
        filter.empty()
            .append('<option value="">همه انبارها</option>')
            .append(`<option value="__no_warehouse__">بدون انبار (${unassignedCount || 0})</option>`);
        dest.empty().append('<option value="">-- انتخاب انبار --</option>');
        warehouses.forEach(w => {
            const count = parseInt(w.item_count, 10) || 0;
            filter.append(`<option value="${w.id}">${UI.escapeHtml(w.name)} (${count})</option>`);
            if (w.is_active == 1) {
                dest.append(`<option value="${w.id}">${UI.escapeHtml(w.name)}</option>`);
            }
        });
        if (currentFilter) filter.val(currentFilter);
        if (currentDest) dest.val(currentDest);
    }

    async function loadWarehouses() {
        const tbody = $('#warehouses-table-body');
        tbody.html('<tr><td colspan="8" class="text-center"><div class="spinner-border spinner-border-sm"></div></td></tr>');

        const result = await Api.call('get_warehouses');
        const warehouses = result?.warehouses || [];
        const unassigned = parseInt(result?.unassigned_count, 10) || 0;
        fillWarehouseFilterSelect(warehouses, unassigned);
        $('#warehouses-unassigned-note').text(unassigned > 0
            ? `${unassigned} فرش هنوز به هیچ انباری وصل نیستند.`
            : 'فرش بدون انبار ندارید.');

        if (warehouses.length === 0) {
            tbody.html('<tr><td colspan="8" class="text-center text-muted">هیچ انباری تعریف نشده است.</td></tr>');
            return;
        }

        tbody.empty();
        warehouses.forEach(w => {
            const count = parseInt(w.item_count, 10) || 0;
            const onDash = w.show_on_dashboard == 1;
            const isOrder = w.warehouse_type === 'order';
            const row = $(`<tr>
                <td><strong>${UI.escapeHtml(w.name)}</strong> ${isOrder ? '<span class="badge bg-primary">سفارش</span>' : ''}</td>
                <td>${UI.escapeHtml(w.address || '-')}</td>
                <td>${UI.escapeHtml(w.phone || '-')}</td>
                <td><span class="badge bg-light text-dark border">${count}</span></td>
                <td>${w.is_default == 1
                    ? '<span class="badge bg-success">پیش‌فرض</span>'
                    : '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 btn-set-default-warehouse">پیش‌فرض</button>'}</td>
                <td><button type="button" class="btn btn-sm py-0 px-2 btn-toggle-dashboard-warehouse ${onDash ? 'btn-info' : 'btn-outline-secondary'}" title="نمایش در داشبورد">${onDash ? 'در داشبورد' : 'نمایش'}</button></td>
                <td>${w.is_active == 1 ? '<span class="badge bg-success">فعال</span>' : '<span class="badge bg-secondary">غیرفعال</span>'}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary btn-view-warehouse-stock py-0 px-1" data-id="${w.id}" title="مشاهده موجودی"><i class="bi bi-list-ul"></i></button>
                    <button class="btn btn-sm btn-outline-primary btn-move-warehouse-all py-0 px-1" data-id="${w.id}" data-count="${count}" title="انتقال همه"><i class="bi bi-arrow-left-right"></i></button>
                    <button class="btn btn-sm ${isOrder ? 'btn-outline-secondary' : 'btn-outline-primary'} btn-convert-order-warehouse py-0 px-1" title="${isOrder ? 'تبدیل به انبار فیزیکی' : 'تبدیل به انبار سفارش'}">${isOrder ? 'فیزیکی' : 'سفارش'}</button>
                    <button class="btn btn-sm btn-outline-warning btn-edit-warehouse py-0 px-1" title="ویرایش"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-delete-warehouse py-0 px-1" data-id="${w.id}" title="حذف"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`);
            row.find('.btn-edit-warehouse, .btn-set-default-warehouse, .btn-toggle-dashboard-warehouse, .btn-convert-order-warehouse').data('warehouse', w);
            tbody.append(row);
        });
    }

    async function loadWarehouseStock() {
        const body = $('#warehouse-stock-body');
        if (!body.length) return;
        body.html('<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm"></div></td></tr>');
        const sizeVal = $('#warehouse-stock-size').val() || '';
        const result = await Api.call('list_warehouse_stock', {
            search: $('#warehouse-stock-search').val() || '',
            warehouse_id: $('#warehouse-stock-filter').val() || '',
            minArea: sizeVal === 'kenareh' ? '' : parseAreaNumber($('#warehouse-stock-min-area').val()),
            maxArea: sizeVal === 'kenareh' ? '' : parseAreaNumber($('#warehouse-stock-max-area').val()),
            shapeFilter: sizeVal === 'kenareh' ? 'kenareh' : '',
            limit: 300
        });
        const items = result?.items || [];
        const summary = result?.summary || {};
        $('#warehouse-stock-summary').html(
            `<span class="badge bg-secondary">قابل‌انتقال: ${summary.movable || 0}</span>` +
            `<span class="badge bg-warning text-dark">بدون انبار: ${summary.unassigned || 0}</span>` +
            `<span class="badge bg-light text-dark border">نمایش: ${items.length}</span>`
        );
        if (!items.length) {
            body.html('<tr><td colspan="7" class="text-center text-muted">فرشی در این فیلتر نیست.</td></tr>');
            return;
        }
        body.empty();
        items.forEach(item => {
            let status = '<span class="badge bg-success">موجود</span>';
            if (item.status === 'returned') {
                status = '<span class="badge bg-info text-dark">مرجوعی</span>';
            } else if (item.status === 'sold') {
                status = '<span class="badge bg-primary">فروخته‌شده</span>';
            }
            const canMove = item.status === 'in_stock' || item.status === 'returned';
            const thumb = item.product_thumb || item.product_image_full || '';
            const full = item.product_image_full || item.product_thumb || '';
            const thumbHtml = thumb
                ? `<img src="${thumb}" data-full="${full || thumb}" data-title="${UI.escapeHtml((item.product_name || 'فرش') + ' — ' + (item.serial_id || ''))}" class="rounded product-image-preview" loading="lazy" decoding="async" style="width: 40px; height: 40px; object-fit: cover; cursor: zoom-in;" alt="" title="برای مشاهده تصویر اصلی کلیک کنید">`
                : `<div class="bg-light rounded d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;"><i class="bi bi-image text-muted"></i></div>`;
            body.append(`<tr>
                <td>${canMove ? `<input type="checkbox" class="warehouse-stock-checkbox" value="${item.id}">` : ''}</td>
                <td>${thumbHtml}</td>
                <td><code>${UI.escapeHtml(item.serial_id || '-')}</code></td>
                <td>${UI.escapeHtml(item.product_name || '-')}</td>
                <td>${UI.escapeHtml(item.dimensions || '-')}</td>
                <td>${UI.escapeHtml(item.warehouse_name || 'بدون انبار')}</td>
                <td>${status}</td>
            </tr>`);
        });
    }

    function prepareWarehouseModal(warehouse) {
        UI.hideModalError('#warehouseFormModal');
        $('#warehouse-form')[0].reset();
        
        $('#warehouse-id').val(warehouse ? warehouse.id : '');
        $('#warehouse-name').val(warehouse ? warehouse.name : '');
        $('#warehouse-address').val(warehouse ? warehouse.address : '');
        $('#warehouse-phone').val(warehouse ? warehouse.phone : '');
        $('#warehouse-is-default').prop('checked', warehouse ? warehouse.is_default == 1 : false);
        $('#warehouse-is-active').prop('checked', warehouse ? warehouse.is_active == 1 : true);
        $('#warehouse-show-on-dashboard').prop('checked', warehouse ? warehouse.show_on_dashboard == 1 : false);
        $('#warehouse-type').val(warehouse && warehouse.warehouse_type === 'order' ? 'order' : 'physical');
        
        $('#warehouseFormModalTitle').text(warehouse ? 'ویرایش انبار' : 'افزودن انبار');
        $('#warehouseFormModal').modal('show');
    }

    return {
        init
    };
})();