// /js/entity-manager.js

function createEntityManager(config) {
    const state = AppConfig.TABLE_STATES[config.tableName];

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

    async function handleFormSubmit(data) {
        UI.showLoader();
        const result = await Api.call(`save_${config.apiName}`, data);
        UI.hideLoader();
        if (result?.success) {
            if (config.modalId) $(config.modalId).modal('hide');

            if (config.refreshCache) {
                await App.fetchInitialCache();
            }

            if (Array.isArray(config.refreshTables)) {
                for (const table of config.refreshTables) {
                    const manager = App.getManager(table);
                    if (manager) {
                        await manager.load();
                    }
                }
            } else {
                await load();
            }
        }
    }

    async function handleDelete(id) {
        UI.confirmAction(`آیا از حذف این ${config.nameFa} مطمئن هستید؟`, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const result = await Api.call(`delete_${config.apiName}`, { id });
                UI.hideLoader();
                if (result?.success) {
                    if (config.refreshCache) {
                        await App.fetchInitialCache();
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
                config.prepareModal($(this).data('entity'));
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
            $(config.addBtnId).on('click', () => config.prepareModal(null));
        }

        if (config.formId) {
            $(config.formId).on('submit', function (e) {
                e.preventDefault();
                const data = config.getFormData();
                handleFormSubmit(data);
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
        getState: () => state,
    };

    for (const key in config) {
        if (typeof config[key] === 'function' && !manager.hasOwnProperty(key)) {
            manager[key] = config[key];
        }
    }

    return manager;
}