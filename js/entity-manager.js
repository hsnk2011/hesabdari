// /js/entity-manager.js

function createEntityManager(config) {
    const state = AppConfig.TABLE_STATES[config.tableName];
    let activeOnSaveCallback = null; // FIX: Use a scoped variable for the callback to prevent it from getting lost.

    async function load() {
        UI.showLoader();
        state.tableName = config.tableName;
        const response = await Api.call('get_paginated_data', state);
        if (response) {
            if (config.renderTable) {
                config.renderTable(response.data);
            }
            UI.renderPagination(config.tableName, response.totalRecords, state);
            UI.updateSortIndicators(config.tableName, state);
        }
        UI.hideLoader();
    }

    async function handleDelete(id) {
        UI.confirmAction(`آیا از حذف این ${config.nameFa} مطمئن هستید؟`, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const result = await Api.call(`delete_${config.apiName}`, { id });
                UI.hideLoader();
                if (result?.success) {
                    UI.showSuccess(`${config.nameFa} با موفقیت حذف شد.`);
                    if (config.refreshCacheKeys) {
                        await App.updateCache(config.refreshCacheKeys);
                    }
                    if (Array.isArray(config.refreshTables)) {
                        for (const table of config.refreshTables) {
                            const manager = App.getManager(table);
                            if (manager) await manager.load();
                        }
                    } else {
                        await load();
                    }
                }
            }
        });
    }

    function attachEvents() {
        const tableId = `#${config.tableName.replace(/_/g, '-')}-table`;
        const searchInputId = `#${config.apiName}-search`;

        $('body').on('click', `${tableId} .btn-edit`, function () {
            if ($(this).data('type') === config.apiName) {
                UI.hideModalError(config.modalId);
                manager.prepareModal($(this).data('entity'), null);
            }
        });

        $('body').on('click', `${tableId} .btn-delete`, function (e) {
            e.stopImmediatePropagation();
            if ($(this).data('type') === config.apiName) {
                if (config.handleDelete) {
                    config.handleDelete($(this).data('id'), $(this).data('username'));
                } else {
                    handleDelete($(this).data('id'));
                }
            }
        });

        if (config.addBtnId) {
            $(config.addBtnId).on('click', () => {
                UI.hideModalError(config.modalId);
                manager.prepareModal(null, null);
            });
        }

        if (config.formId) {
            $(config.formId).on('submit', async function (e) {
                e.preventDefault();
                const onSave = activeOnSaveCallback; // Read from the scoped variable
                activeOnSaveCallback = null;      // Clear it for the next use

                const data = config.getFormData();
                UI.hideModalError(config.modalId);
                UI.showLoader();
                const result = await Api.call(`save_${config.apiName}`, data);
                UI.hideLoader();

                if (result?.success) {
                    const savedData = { ...data, id: result.id };

                    if (onSave) {
                        // ** THE FINAL FIX IS HERE **
                        // Before hiding the modal, explicitly remove focus from any element within it.
                        // This prevents the "aria-hidden" focus conflict reported in the console.
                        if (document.activeElement) {
                            document.activeElement.blur();
                        }

                        $(config.modalId).one('hidden.bs.modal', function () {
                            onSave(savedData);
                        });
                        $(config.modalId).modal('hide');
                    } else {
                        UI.showSuccess(`${config.nameFa} با موفقیت ذخیره شد.`);
                        $(config.modalId).modal('hide');

                        if (config.refreshCacheKeys) {
                            await App.updateCache(config.refreshCacheKeys);
                        }
                        if (Array.isArray(config.refreshTables)) {
                            for (const table of config.refreshTables) {
                                const manager = App.getManager(table);
                                if (manager) await manager.load();
                            }
                        } else {
                            await load();
                        }
                    }
                } else if (result?.error) {
                    UI.showModalError(config.modalId, result.error);
                }
            });
        }


        if ($(searchInputId).length) {
            const searchHandler = UI.debounce(() => {
                state.searchTerm = $(searchInputId).val();
                state.currentPage = 1;
                load();
            }, 500);
            $(searchInputId).on('input', searchHandler);
        }
    }

    const manager = {
        init: attachEvents,
        load,
        prepareModal: (entity = null, onSaveCallback = null) => {
            activeOnSaveCallback = onSaveCallback; // Set the scoped variable
            config.prepareModal(entity);
        },
        getState: () => state,
    };

    for (const key in config) {
        if (typeof config[key] === 'function' && !manager.hasOwnProperty(key)) {
            manager[key] = config[key];
        }
    }

    return manager;
}