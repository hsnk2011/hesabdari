// /js/collection-manager.js

const CollectionManager = (function () {
    let attributeTypesCache = [];
    let collectionsCache = [];
    let standardDimensionsCache = [];

    function renderAttributeTypes() {
        const container = $('#attribute-types-list-container').empty();
        if (!attributeTypesCache.length) {
            container.html('<li class="list-group-item">نوع مشخصه‌ای یافت نشد.</li>');
            return;
        }
        attributeTypesCache.forEach(attr => {
            const applicableMap = { machine: 'ماشینی', handmade: 'دستبافت', both: 'هردو' };
            const item = $(`<li class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0">${UI.escapeHtml(attr.name)}</h6>
                    <small class="text-muted">برای: ${applicableMap[attr.applicable_to]}</small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-warning btn-edit-attr" data-id="${attr.id}"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-delete-attr" data-id="${attr.id}"><i class="bi bi-trash"></i></button>
                </div>
            </li>`);
            container.append(item);
        });
    }

    function renderCollections() {
        const container = $('#collections-list-container').empty();
        if (!collectionsCache.length) {
            container.html('<p class="text-center p-3">کلکسیونی یافت نشد.</p>');
            return;
        }

        const accordion = $('<div class="accordion" id="collectionsAccordion"></div>');
        collectionsCache.forEach((collection, index) => {
            let attributesHtml = '<ul class="list-unstyled list-group list-group-flush">';
            (collection.attributes || []).forEach(attr => {
                attributesHtml += `<li class="list-group-item d-flex justify-content-between py-1"><span>${UI.escapeHtml(attr.name)}:</span> <strong>${UI.escapeHtml(attr.value)}</strong></li>`;
            });
            attributesHtml += '</ul>';

            const item = $(`
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading-${index}">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-${index}">
                            <div class="d-flex justify-content-between w-100 align-items-center pe-4">
                                <div>
                                    <strong>${UI.escapeHtml(collection.name)}</strong>
                                    <span class="badge bg-${collection.type === 'machine' ? 'info' : 'success'}">${collection.type === 'machine' ? 'ماشینی' : 'دستبافت'}</span>
                                </div>
                                <div class="collection-buttons">
                                    <span class="badge bg-secondary me-2">قیمت/متر: ${UI.formatCurrency(collection.price_per_meter)}</span>
                                    <button class="btn btn-sm btn-outline-primary btn-manage-products" data-id="${collection.id}" data-name="${UI.escapeHtml(collection.name)}" title="مدیریت محصولات"><i class="bi bi-box-seam"></i></button>
                                    <button class="btn btn-sm btn-warning btn-edit-coll" data-id="${collection.id}"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-danger btn-delete-coll" data-id="${collection.id}"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                        </button>
                    </h2>
                    <div id="collapse-${index}" class="accordion-collapse collapse" data-bs-parent="#collectionsAccordion">
                        <div class="accordion-body">
                            <h6>مشخصات فنی:</h6>
                            ${attributesHtml}
                        </div>
                    </div>
                </div>`);

            accordion.append(item);
        });
        container.append(accordion);
    }

    function prepareAttributeTypeModal(attr = null) {
        UI.hideModalError('#attributeTypeModal');
        $('#attribute-type-form')[0].reset();
        $('#attribute-type-id').val(attr ? attr.id : '');
        $('#attribute-type-name').val(attr ? attr.name : '');
        $('#attribute-type-applicable-to').val(attr ? attr.applicable_to : 'both');
        $('#attributeTypeModal').modal('show');
    }

    function prepareCollectionModal(collection = null) {
        UI.hideModalError('#collectionModal');
        $('#collection-form')[0].reset();
        $('#collection-id').val(collection ? collection.id : '');
        $('#collection-name').val(collection ? collection.name : '');
        $('#collection-price').val(collection ? Number(collection.price_per_meter).toLocaleString('en-US') : '0');

        const type = collection ? collection.type : 'machine';
        $(`input[name="collection-type"][value="${type}"]`).prop('checked', true);

        renderCollectionAttributesForm(type, collection);
        renderCollectionDimensionsForm(collection);

        $('#collectionModal').modal('show');
    }

    function renderCollectionAttributesForm(type, collection = null) {
        const container = $('#collection-attributes-container').empty();
        const relevantAttributes = attributeTypesCache.filter(at => at.applicable_to === 'both' || at.applicable_to === type);

        if (!relevantAttributes.length) {
            container.html('<p class="text-muted">هیچ نوع مشخصه مرتبطی برای این نوع کلکسیون تعریف نشده است.</p>');
            return;
        }

        relevantAttributes.forEach(attr => {
            const savedAttr = collection?.attributes.find(a => a.attribute_type_id == attr.id);
            const value = savedAttr ? savedAttr.value : '';
            const field = $(`<div class="col-md-4 mb-3">
                <label for="attr-input-${attr.id}" class="form-label">${UI.escapeHtml(attr.name)}</label>
                <input type="text" class="form-control" id="attr-input-${attr.id}" data-attr-id="${attr.id}" value="${value}">
            </div>`);
            container.append(field);
        });
    }

    function renderCollectionDimensionsForm(collection = null) {
        const container = $('#collection-dimensions-container').empty();

        // FIX: Merge any existing collection dimensions that are no longer in the
        // standard dimensions list, so they are still rendered (and not wiped on save).
        const dimensionsToRender = [...(standardDimensionsCache || [])];
        if (collection && Array.isArray(collection.dimensions)) {
            collection.dimensions.forEach(d => {
                if (d && d.dimension && !dimensionsToRender.includes(d.dimension)) {
                    dimensionsToRender.push(d.dimension);
                }
            });
        }

        if (dimensionsToRender.length === 0) {
            container.html('<p class="text-muted">لیست ابعاد استاندارد تعریف نشده است. لطفا از بخش تنظیمات اقدام کنید.</p>');
            return;
        }

        dimensionsToRender.forEach(dim => {
            // *** START FIX: Check for existence of collection and collection.dimensions ***
            const savedDim = (collection && collection.dimensions) ? collection.dimensions.find(d => d.dimension === dim) : null;
            // *** END FIX ***
            const isChecked = savedDim ? 'checked' : '';
            const priceOverride = savedDim && savedDim.price_override ? Number(savedDim.price_override).toLocaleString('en-US') : '';

            const row = $(`<div class="col-md-4">
                <div class="input-group input-group-sm mb-2">
                    <div class="input-group-text">
                        <input class="form-check-input mt-0" type="checkbox" value="${dim}" ${isChecked}>
                    </div>
                    <span class="input-group-text" style="width: 100px;">${dim}</span>
                    <input type="text" class="form-control numeric-input price-override" placeholder="قیمت سفارشی" value="${priceOverride}">
                </div>
            </div>`);
            container.append(row);
        });
    }

    async function handleAttributeTypeFormSubmit(e) {
        e.preventDefault();
        const data = {
            id: $('#attribute-type-id').val(),
            name: $('#attribute-type-name').val(),
            applicable_to: $('#attribute-type-applicable-to').val()
        };
        const result = await Api.call('save_attribute_type', data);
        if (result?.success) {
            UI.showSuccess('نوع مشخصه با موفقیت ذخیره شد.');
            $('#attributeTypeModal').modal('hide');
            load();
        } else if (result?.error) {
            UI.showModalError('#attributeTypeModal', result.error);
        }
    }

    async function handleCollectionFormSubmit(e) {
        e.preventDefault();
        const attributes = [];
        $('#collection-attributes-container input').each(function () {
            attributes.push({
                id: $(this).data('attr-id'),
                value: $(this).val()
            });
        });

        const dimensions = [];
        $('#collection-dimensions-container .input-group').each(function () {
            const checkbox = $(this).find('input[type="checkbox"]');
            if (checkbox.is(':checked')) {
                const priceOverride = $(this).find('.price-override').val().replace(/,/g, '');
                dimensions.push({
                    dimension: checkbox.val(),
                    price_override: priceOverride ? parseFloat(priceOverride) : null
                });
            }
        });

        const data = {
            id: $('#collection-id').val(),
            name: $('#collection-name').val(),
            type: $('input[name="collection-type"]:checked').val(),
            price_per_meter: $('#collection-price').val().replace(/,/g, ''),
            attributes: attributes,
            dimensions: dimensions
        };

        const result = await Api.call('save_collection', data);
        if (result?.success) {
            UI.showSuccess('کلکسیون با موفقیت ذخیره شد.');
            $('#collectionModal').modal('hide');
            await App.updateCache(['collections']);
            load();
        } else if (result?.error) {
            UI.showModalError('#collectionModal', result.error);
        }
    }

    async function handleDelete(type, id, name) {
        UI.confirmAction(`آیا از حذف "${name}" مطمئن هستید؟`, async (confirmed) => {
            if (confirmed) {
                const action = type === 'attr' ? 'delete_attribute_type' : 'delete_collection';
                const result = await Api.call(action, { id });
                if (result?.success) {
                    UI.showSuccess('مورد با موفقیت حذف شد.');
                    await App.updateCache(['collections']);
                    load();
                }
            }
        });
    }

    async function load() {
        UI.showLoader();
        try {
            // Public settings are loaded for every user (get_app_settings needs manage_settings).
            const cache = App.getCache();
            const dims = (cache.settings && cache.settings.standard_dimensions)
                || (cache.publicSettings && cache.publicSettings.standard_dimensions) || '';
            standardDimensionsCache = dims ? dims.split(',').map(s => s.trim()).filter(Boolean) : [];

            const [attrsData, collsData] = await Promise.all([
                Api.call('get_attribute_types'),
                Api.call('get_collections')
            ]);

            if (attrsData) {
                attributeTypesCache = attrsData;
                renderAttributeTypes();
            }
            if (collsData) {
                collectionsCache = collsData;
                renderCollections();
            }
        } finally {
            UI.hideLoader();
        }
    }

    // ========== Product Management for Collections ==========
    let currentCollectionIdForProducts = null;
    let allProductsCache = [];
    let collectionProductsCache = [];

    async function openManageProductsModal(collectionId, collectionName) {
        currentCollectionIdForProducts = collectionId;
        $('#manageCollectionProductsModalTitle').text(`مدیریت محصولات کلکسیون: ${collectionName}`);
        UI.hideModalError('#manageCollectionProductsModal');
        
        // Clear search inputs and checkboxes
        $('#search-collection-products').val('');
        $('#search-available-products').val('');
        $('#select-all-collection-products').prop('checked', false);
        $('#select-all-available-products').prop('checked', false);
        
        // Load products
        UI.showLoader();
        const [collectionProducts, allProducts] = await Promise.all([
            Api.call('get_collection_products', { collectionId }),
            Api.call('get_all_products_for_selection')
        ]);
        UI.hideLoader();

        allProductsCache = allProducts || [];
        collectionProductsCache = collectionProducts || [];
        
        renderCollectionProductsList(collectionProductsCache);
        renderAvailableProductsList(allProductsCache, collectionProductsCache);
        
        $('#manageCollectionProductsModal').modal('show');
    }

    function renderCollectionProductsList(products) {
        const container = $('#collection-products-list').empty();
        
        if (!products || products.length === 0) {
            container.html('<p class="text-muted text-center py-3">هیچ محصولی در این کلکسیون وجود ندارد.</p>');
            return;
        }

        products.forEach(p => {
            const item = $(`
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <input type="checkbox" class="form-check-input me-2 product-checkbox" value="${p.id}">
                        <strong>${UI.escapeHtml(p.name)}</strong>
                        ${p.design_code ? `<small class="text-muted ms-2">(کد: ${p.design_code})</small>` : ''}
                        ${p.colorway ? `<small class="text-muted ms-2">رنگ: ${p.colorway}</small>` : ''}
                    </div>
                </div>
            `);
            container.append(item);
        });
    }

    function renderAvailableProductsList(allProducts, collectionProducts) {
        const container = $('#available-products-list').empty();
        const collectionProductIds = (collectionProducts || []).map(p => p.id);
        
        // Filter out products already in this collection
        const availableProducts = allProducts.filter(p => !collectionProductIds.includes(p.id));
        
        if (availableProducts.length === 0) {
            container.html('<p class="text-muted text-center py-3">همه محصولات در یک کلکسیون قرار دارند.</p>');
            return;
        }

        availableProducts.forEach(p => {
            const collectionBadge = p.collection_name 
                ? `<span class="badge bg-info ms-2">${p.collection_name}</span>` 
                : '<span class="badge bg-secondary ms-2">بدون کلکسیون</span>';
            
            const item = $(`
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <input type="checkbox" class="form-check-input me-2 available-product-checkbox" value="${p.id}">
                        <strong>${UI.escapeHtml(p.name)}</strong>
                        ${p.design_code ? `<small class="text-muted ms-2">(کد: ${p.design_code})</small>` : ''}
                        ${collectionBadge}
                    </div>
                </div>
            `);
            container.append(item);
        });
    }

    async function handleAddProductsToCollection() {
        const selectedIds = [];
        $('#available-products-list .available-product-checkbox:checked').each(function() {
            selectedIds.push(parseInt($(this).val()));
        });

        if (selectedIds.length === 0) {
            UI.showModalError('#manageCollectionProductsModal', 'لطفاً حداقل یک محصول را انتخاب کنید.');
            return;
        }

        UI.showLoader();
        const result = await Api.call('add_products_to_collection', {
            collectionId: currentCollectionIdForProducts,
            productIds: selectedIds
        });
        UI.hideLoader();

        if (result?.success) {
            UI.showSuccess(`${selectedIds.length} محصول به کلکسیون اضافه شد.`);
            // Refresh the modal data
            const coll = collectionsCache.find(c => c.id == currentCollectionIdForProducts);
            await openManageProductsModal(currentCollectionIdForProducts, coll?.name || '');
            await App.updateCache(['products']);
        } else if (result?.error) {
            UI.showModalError('#manageCollectionProductsModal', result.error);
        }
    }

    async function handleRemoveProductsFromCollection() {
        const selectedIds = [];
        $('#collection-products-list .product-checkbox:checked').each(function() {
            selectedIds.push(parseInt($(this).val()));
        });

        if (selectedIds.length === 0) {
            UI.showModalError('#manageCollectionProductsModal', 'لطفاً حداقل یک محصول را انتخاب کنید.');
            return;
        }

        UI.confirmAction(`آیا از حذف ${selectedIds.length} محصول از این کلکسیون مطمئن هستید؟`, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const result = await Api.call('remove_products_from_collection', {
                    productIds: selectedIds
                });
                UI.hideLoader();

                if (result?.success) {
                    UI.showSuccess(`${selectedIds.length} محصول از کلکسیون حذف شد.`);
                    // Refresh the modal data
                    const coll = collectionsCache.find(c => c.id == currentCollectionIdForProducts);
                    await openManageProductsModal(currentCollectionIdForProducts, coll?.name || '');
                    await App.updateCache(['products']);
                } else if (result?.error) {
                    UI.showError(result.error);
                }
            }
        });
    }

    function attachEvents() {
        $('#add-attribute-type-btn').on('click', () => prepareAttributeTypeModal(null));
        $('#add-collection-btn').on('click', () => prepareCollectionModal(null));

        $('#attribute-type-form').on('submit', handleAttributeTypeFormSubmit);
        $('#collection-form').on('submit', handleCollectionFormSubmit);

        $('input[name="collection-type"]').on('change', function () {
            const collectionId = $('#collection-id').val();
            const collection = collectionId ? collectionsCache.find(c => c.id == collectionId) : null;
            renderCollectionAttributesForm($(this).val(), collection);
        });

        $('body').on('click', '#manage-product-options-btn', function () {
            const settingsTabButton = document.querySelector('#mainTab button[data-bs-target="#settings"]');
            if (settingsTabButton) {
                new bootstrap.Tab(settingsTabButton).show();
            }
            setTimeout(() => {
                const collectionsSubTabButton = document.querySelector('#settings-sub-tab button[data-bs-target="#collections-pane"]');
                if (collectionsSubTabButton) {
                    new bootstrap.Tab(collectionsSubTabButton).show();
                }
            }, 150);
        });

        $('#collections-list-container').on('click', '.btn-edit-coll', function (e) {
            e.stopPropagation();
            const id = $(this).data('id');
            const coll = collectionsCache.find(c => c.id == id);
            prepareCollectionModal(coll);
        });
        $('#collections-list-container').on('click', '.btn-delete-coll', function (e) {
            e.stopPropagation();
            const id = $(this).data('id');
            const coll = collectionsCache.find(c => c.id == id);
            handleDelete('coll', id, coll.name);
        });

        // Manage Products Button
        $('#collections-list-container').on('click', '.btn-manage-products', function (e) {
            e.stopPropagation();
            const id = $(this).data('id');
            const name = $(this).data('name');
            openManageProductsModal(id, name);
        });

        // Add/Remove products buttons
        $(document).on('click', '#btn-add-products-to-collection', handleAddProductsToCollection);
        $(document).on('click', '#btn-remove-products-from-collection', handleRemoveProductsFromCollection);

        $('#attribute-types-list-container').on('click', '.btn-edit-attr', function () {
            const id = $(this).data('id');
            const attr = attributeTypesCache.find(a => a.id == id);
            prepareAttributeTypeModal(attr);
        });
        $('#attribute-types-list-container').on('click', '.btn-delete-attr', function () {
            const id = $(this).data('id');
            const attr = attributeTypesCache.find(a => a.id == id);
            handleDelete('attr', id, attr.name);
        });
    }

    return {
        init: attachEvents,
        load
    };
})();