// /js/entity-manager.js

function createEntityManager(config) {
    const state = AppConfig.TABLE_STATES[config.tableName];

    async function load(filters = null, message = null) {
        UI.showLoader();

        // START FIX: Handle incoming filters from smart alerts
        const filterInfoContainer = $(`#${config.tableName}-filter-info`).empty();
        state.tableName = config.tableName;
        let payload = { ...state };

        if (filters) {
            payload = { ...payload, ...filters };
            state.filterType = filters.filterType; // Persist filter type in state
            if (message) {
                const clearButton = $('<button class="btn btn-sm btn-warning ms-2">حذف فیلتر</button>');
                clearButton.on('click', () => {
                    delete state.filterType;
                    const searchInput = $(`#${config.apiName}-search`);
                    if (searchInput.length) {
                        searchInput.val('');
                        state.searchTerm = '';
                    }
                    load();
                });
                filterInfoContainer.html(`<div class="alert alert-info py-1">${message}</div>`).find('div').append(clearButton);
            }
        } else {
            // Clear filter type if not provided
            delete state.filterType;
        }

        if (state.balanceFilter) {
            payload.balanceFilter = state.balanceFilter;
        }
        if (state.includeUncashedChecks) {
            payload.includeUncashedChecks = true;
        }

        const response = await Api.call('get_paginated_data', payload);
        // END FIX

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

    const manager = {
        init: function () {
            const tableId = `#${config.tableName.replace(/_/g, '-')}-table`;
            const searchInputId = `#${config.apiName}-search`;

            $('body').on('click', `${tableId} .btn-edit`, function () {
                if ($(this).data('type') === config.apiName) {
                    UI.hideModalError(config.modalId);
                    // Ensure the manager's prepareModal is called, which will be the one from the config
                    manager.prepareModal($(this).data('entity'));
                }
            });

            $('body').on('click', `${tableId} .btn-delete`, function (e) {
                if ($(this).data('type') === config.apiName) {
                    handleDelete($(this).data('id'));
                }
            });

            if (config.addBtnId) {
                $(config.addBtnId).on('click', () => {
                    UI.hideModalError(config.modalId);
                    manager.prepareModal(null);
                });
            }

            if (config.formId) {
                $(config.formId).off('submit').on('submit', async function (e) {
                    e.preventDefault();
                    if (!this.checkValidity()) {
                        this.reportValidity();
                        return;
                    }

                    // This now correctly calls the getFormData from product-manager.js
                    const data = manager.getFormData();
                    UI.hideModalError(config.modalId);
                    UI.showLoader();
                    const result = await Api.call(`save_${config.apiName}`, data);
                    UI.hideLoader();

                    if (result?.success) {
                        UI.showSuccess(`${config.nameFa} با موفقیت ذخیره شد.`);
                        $(config.modalId).modal('hide');

                        if (config.onSuccess) {
                            config.onSuccess(result, data);
                        } else {
                            if (config.refreshCacheKeys) {
                                await App.updateCache(config.refreshCacheKeys);
                            }
                            await load();
                        }
                    } else if (result?.error) {
                        UI.showModalError(config.modalId, result.error);
                    }
                });
            }

            if ($(searchInputId).length) {
                const searchHandler = UI.debounce(() => {
                    delete state.filterType; // Clear special filters when searching
                    $(`#${config.tableName}-filter-info`).empty();
                    state.searchTerm = $(searchInputId).val();
                    state.currentPage = 1;
                    load();
                }, 500);
                $(searchInputId).on('input', searchHandler);
            }
        },
        load,
        // Always use the functions passed from the specific manager's config
        prepareModal: config.prepareModal,
        getFormData: config.getFormData,
        handleDelete: handleDelete
    };

    return manager;
}