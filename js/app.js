// /js/app.js

const App = (function () {
    let appCache = { customers: [], suppliers: [], products: [], partners: [], checks: [], accounts: [] };
    let managers = {};
    let notificationInterval;
    let appState = {
        currentUser: null,
        currentEntityId: null,
        businessEntities: []
    };

    function getManager(name) {
        return managers[name];
    }

    function registerManager(name, manager) {
        managers[name] = manager;
    }

    function getCache() {
        return appCache;
    }

    function getAppState() {
        return appState;
    }

    async function refreshAllData() {
        await fetchInitialCache();

        const activeTab = document.querySelector('#mainTab .nav-link.active, #mainTab .dropdown-item.active');
        if (activeTab) {
            const tabId = activeTab.id;
            const tableName = activeTab.dataset.tableName;

            if (tabId === 'dashboard-tab') {
                await Dashboard.load();
            } else if (tableName) {
                const manager = getManager(tableName);
                if (manager) {
                    await manager.load();
                } else if (tableName.includes('invoice') || tableName.includes('consignment')) {
                    await InvoiceManager.load(tableName);
                }
            }
        } else {
            // If no active tab is found (e.g., first load), load dashboard
            await Dashboard.load();
        }
    }

    async function switchEntity(entityId) {
        UI.showLoader();
        const result = await Api.call('switch_entity', { entity_id: entityId });
        if (result?.success) {
            appState.currentEntityId = result.new_entity_id;
            updateEntitySwitcherUI();
            await refreshAllData();
            UI.showSuccess(`مجموعه فعال به «${appState.businessEntities.find(e => e.id == entityId).name}» تغییر کرد.`);
        }
        UI.hideLoader();
    }

    function updateEntitySwitcherUI() {
        const currentEntity = appState.businessEntities.find(e => e.id == appState.currentEntityId);
        if (currentEntity) {
            $('#current-entity-name').text(currentEntity.name);
        }

        const list = $('#entity-switcher-list').empty();
        appState.businessEntities.forEach(entity => {
            const item = $(`<li class="dropdown-item ${entity.id == appState.currentEntityId ? 'active' : ''}" href="#" data-entity-id="${entity.id}">${entity.name}</li>`);
            item.on('click', function (e) {
                e.preventDefault();
                const clickedEntityId = $(this).data('entity-id');
                if (clickedEntityId != appState.currentEntityId) {
                    switchEntity(clickedEntityId);
                }
            });
            list.append(item);
        });
    }

    async function updateCache(keys) {
        if (!Array.isArray(keys)) keys = [keys];

        UI.showLoader();
        const promises = [];
        const keyMap = {
            customers: Api.call('get_full_customers_list'),
            suppliers: Api.call('get_full_suppliers_list'),
            products: Api.call('get_full_products_list'),
            partners: Api.call('get_partners'),
            accounts: Api.call('get_full_accounts_list'),
            checks: Api.call('get_paginated_data', { tableName: 'checks', limit: 1000, sortBy: 'id', sortOrder: 'DESC' })
        };

        const keysToUpdate = [...new Set(keys)];
        keysToUpdate.forEach(key => {
            if (keyMap[key]) {
                promises.push(keyMap[key].then(data => ({ key, data })));
            }
        });

        const results = await Promise.all(promises);

        results.forEach(result => {
            if (result.key === 'checks') {
                appCache[result.key] = result.data ? result.data.data : [];
            } else if (result.data) {
                appCache[result.key] = result.data;
            }
        });

        if (keysToUpdate.includes('partners')) PartnerManager.refreshUI();
        if (keysToUpdate.includes('customers') || keysToUpdate.includes('suppliers') || keysToUpdate.includes('partners')) populatePersonSelectForReports();
        if (keysToUpdate.includes('accounts')) populateAccountSelectForReports();
        if (keysToUpdate.includes('products')) populateProductSelectForReports();

        UI.hideLoader();
    }

    async function fetchInitialCache() {
        await updateCache(['customers', 'suppliers', 'products', 'partners', 'accounts', 'checks']);
    }

    async function updateNotifications() {
        const notificationData = await Api.call('get_notifications', {}, false);
        const bell = $('#notification-bell');
        const countBadge = $('#notification-count');
        const notificationList = $('#notification-list');

        if (notificationData && notificationData.due_checks_count > 0) {
            countBadge.text(notificationData.due_checks_count).show();
            bell.show();

            const checksList = await Api.call('get_due_checks_list', {}, false);
            notificationList.find('.notification-item').remove();

            if (checksList && checksList.length > 0) {
                checksList.forEach(chk => {
                    const dueDate = UI.gregorianToPersian(chk.dueDate);
                    const listItem = $(`<li class="notification-item"><a class="dropdown-item" href="#">
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold">چک #${chk.checkNumber}</span>
                            <small>${dueDate}</small>
                        </div>
                        <div class="small text-muted">${UI.formatCurrency(chk.amount)}</div>
                    </a></li>`);
                    listItem.on('click', function (e) {
                        e.preventDefault();
                        const checksTabButton = document.querySelector('#mainTab button[data-bs-target="#checks"]');
                        if (checksTabButton) {
                            new bootstrap.Tab(checksTabButton).show();
                        }
                    });
                    notificationList.append(listItem);
                });
            } else {
                notificationList.append('<li class="notification-item dropdown-item text-muted">موردی یافت نشد.</li>');
            }

        } else {
            countBadge.hide();
            bell.hide();
        }
    }

    function populatePersonSelectForReports() {
        const personSelect = $('#report-person-select').empty().append('<option></option>');
        personSelect.append('<optgroup label="مشتریان"></optgroup>');
        (appCache.customers || []).forEach(c => personSelect.append(`<option value="customer-${c.id}">${c.name}</option>`));
        personSelect.append('<optgroup label="تامین‌کنندگان"></optgroup>');
        (appCache.suppliers || []).forEach(s => personSelect.append(`<option value="supplier-${s.id}">${s.name}</option>`));
        personSelect.append('<optgroup label="شرکا"></optgroup>');
        (appCache.partners || []).forEach(p => personSelect.append(`<option value="partner-${p.id}">${p.name}</option>`));
        if (personSelect.data('select2')) { personSelect.select2('destroy'); }
        personSelect.select2({ theme: 'bootstrap-5', dir: 'rtl', placeholder: "جستجو و انتخاب شخص..." });
    }

    function populateAccountSelectForReports() {
        const accountSelect = $('#report-account-select').empty().append('<option></option>');
        (appCache.accounts || []).forEach(acc => accountSelect.append(`<option value="${acc.id}">${acc.name}</option>`));
        if (accountSelect.data('select2')) { accountSelect.select2('destroy'); }
        accountSelect.select2({ theme: 'bootstrap-5', dir: 'rtl', placeholder: "جستجو و انتخاب حساب..." });
    }

    function populateProductSelectForReports() {
        const productSelect = $('#report-product-select').empty().append('<option></option>');
        (appCache.products || []).forEach(p => productSelect.append(`<option value="${p.id}">${p.name}</option>`));
        if (productSelect.data('select2')) { productSelect.select2('destroy'); }
        productSelect.select2({ theme: 'bootstrap-5', dir: 'rtl', placeholder: "جستجو و انتخاب محصول..." });
    }

    function populateEntitySelectForReports() {
        const entitySelect = $('#report-entity-select').empty();
        (appState.businessEntities || []).forEach(e => {
            entitySelect.append(`<option value="${e.id}">${e.name}</option>`);
        });
        entitySelect.val(appState.currentEntityId);
    }

    function initEntityManagers() {
        managers['customers'] = createEntityManager({
            tableName: 'customers', apiName: 'customer', nameFa: 'مشتری',
            addBtnId: '#add-customer-btn', modalId: '#customerModal', formId: '#customer-form',
            renderTable: (data) => {
                const body = $('#customers-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="6" class="text-center">مشتری یافت نشد.</td></tr>');
                data.forEach(c => {
                    const totalDebt = (parseFloat(c.initial_balance) || 0) + (parseFloat(c.total_unsettled) || 0);
                    const finalBalance = totalDebt - (parseFloat(c.available_credit) || 0);

                    let statusHtml = '';
                    if (finalBalance > 0.01) {
                        statusHtml = `<span class="badge bg-danger">بدهکار: ${UI.formatCurrency(finalBalance)}</span>`;
                    } else if (finalBalance < -0.01) {
                        statusHtml = `<span class="badge bg-success">بستانکار: ${UI.formatCurrency(Math.abs(finalBalance))}</span>`;
                    } else {
                        statusHtml = `<span class="badge bg-secondary">تسویه شده</span>`;
                    }

                    const row = $(`<tr>
                        <td>${c.name}</td>
                        <td>${c.phone || ''}</td>
                        <td class="text-danger">${UI.formatCurrency(c.total_unsettled || 0)}</td>
                        <td class="text-success">${UI.formatCurrency(c.available_credit || 0)}</td>
                        <td>${statusHtml}</td>
                        <td>
                            <button class="btn btn-sm btn-warning btn-edit" data-type="customer"><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-danger btn-delete" data-type="customer" data-id="${c.id}"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>`);
                    row.find('.btn-edit').data('entity', c);
                    body.append(row);
                });
            },
            prepareModal: (customer = null) => {
                $('#customerModal').removeData('source');
                $('#customer-form')[0].reset();
                $('#customer-id').val(customer ? customer.id : '');
                $('#customer-name').val(customer ? customer.name : '');
                $('#customer-address').val(customer ? customer.address : '');
                $('#customer-phone').val(customer ? customer.phone : '');
                $('#customer-national-id').val(customer ? customer.nationalId : '');
                $('#customer-initial-balance').val(customer ? Number(customer.initial_balance).toLocaleString('en-US') : '0');
                $('#customerModal').modal('show');
            },
            getFormData: () => ({
                id: $('#customer-id').val(),
                name: $('#customer-name').val(),
                address: $('#customer-address').val(),
                phone: $('#customer-phone').val(),
                nationalId: $('#customer-national-id').val(),
                initial_balance: $('#customer-initial-balance').val().replace(/,/g, '')
            }),
            onSuccess: (result, formData) => {
                const modal = $('#customerModal');
                if (modal.data('source') === 'sales-invoice') {
                    InvoiceManager.updatePersonDropdown('sales', { id: result.id, name: formData.name });
                } else {
                    managers['customers'].load();
                }
            },
            refreshCacheKeys: ['customers']
        });

        managers['suppliers'] = createEntityManager({
            tableName: 'suppliers', apiName: 'supplier', nameFa: 'تأمین‌کننده',
            addBtnId: '#add-supplier-btn', modalId: '#supplierModal', formId: '#supplier-form',
            renderTable: (data) => {
                const body = $('#suppliers-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="6" class="text-center">تأمین‌کننده‌ای یافت نشد.</td></tr>');
                data.forEach(s => {
                    const totalDebt = (parseFloat(s.initial_balance) || 0) + (parseFloat(s.total_unsettled) || 0);
                    const finalBalance = totalDebt - (parseFloat(s.available_credit) || 0);

                    let statusHtml = '';
                    if (finalBalance > 0.01) {
                        statusHtml = `<span class="badge bg-danger">بدهکار: ${UI.formatCurrency(finalBalance)}</span>`;
                    } else if (finalBalance < -0.01) {
                        statusHtml = `<span class="badge bg-success">بستانکار: ${UI.formatCurrency(Math.abs(finalBalance))}</span>`;
                    } else {
                        statusHtml = `<span class="badge bg-secondary">تسویه شده</span>`;
                    }

                    const row = $(`<tr>
                        <td>${s.name}</td>
                        <td>${s.phone || ''}</td>
                        <td class="text-danger">${UI.formatCurrency(s.total_unsettled || 0)}</td>
                        <td class="text-success">${UI.formatCurrency(s.available_credit || 0)}</td>
                        <td>${statusHtml}</td>
                        <td>
                            <button class="btn btn-sm btn-warning btn-edit" data-type="supplier"><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-danger btn-delete" data-type="supplier" data-id="${s.id}"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>`);
                    row.find('.btn-edit').data('entity', s);
                    body.append(row);
                });
            },
            prepareModal: (supplier = null) => {
                $('#supplierModal').removeData('source');
                $('#supplier-form')[0].reset();
                $('#supplier-id').val(supplier ? supplier.id : '');
                $('#supplier-name').val(supplier ? supplier.name : '');
                $('#supplier-address').val(supplier ? supplier.address : '');
                $('#supplier-phone').val(supplier ? supplier.phone : '');
                $('#supplier-economic-code').val(supplier ? supplier.economicCode : '');
                $('#supplier-initial-balance').val(supplier ? Number(supplier.initial_balance).toLocaleString('en-US') : '0');
                $('#supplierModal').modal('show');
            },
            getFormData: () => ({
                id: $('#supplier-id').val(),
                name: $('#supplier-name').val(),
                address: $('#supplier-address').val(),
                phone: $('#supplier-phone').val(),
                economicCode: $('#supplier-economic-code').val(),
                initial_balance: $('#supplier-initial-balance').val().replace(/,/g, '')
            }),
            onSuccess: (result, formData) => {
                const modal = $('#supplierModal');
                if (modal.data('source') === 'purchase-invoice') {
                    InvoiceManager.updatePersonDropdown('purchase', { id: result.id, name: formData.name });
                } else {
                    managers['suppliers'].load();
                }
            },
            refreshCacheKeys: ['suppliers']
        });

        managers['products'] = createEntityManager({
            tableName: 'products', apiName: 'product', nameFa: 'محصول',
            addBtnId: '#add-product-btn', modalId: '#productModal', formId: '#product-form',
            renderTable: (data) => {
                const body = $('#products-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="4" class="text-center">محصولی یافت نشد.</td></tr>');
                data.forEach(p => {
                    let stockHtml = '<ul class="list-unstyled mb-0">';
                    if (p.stock && p.stock.length > 0) p.stock.filter(s => s.quantity > 0).forEach(s => stockHtml += `<li><strong>${s.dimensions}:</strong> ${s.quantity} عدد</li>`);
                    else stockHtml += '<li>موجودی ثبت نشده</li>';
                    stockHtml += '</ul>';
                    const row = $(`<tr><td>${p.name}</td><td>${p.description || ''}</td><td>${stockHtml}</td><td><button class="btn btn-sm btn-warning btn-edit" data-type="product"><i class="bi bi-pencil-square"></i></button> <button class="btn btn-sm btn-danger btn-delete" data-type="product" data-id="${p.id}"><i class="bi bi-trash"></i></button></td></tr>`);
                    row.find('.btn-edit').data('entity', p);
                    body.append(row);
                });
            },
            prepareModal: (product = null) => {
                $('#product-form')[0].reset();
                $('#product-id').val('');
                $('#product-stock-container').empty();
                if (product) {
                    $('#product-id').val(product.id);
                    $('#product-name').val(product.name);
                    $('#product-description').val(product.description);
                    if (product.stock?.length) product.stock.forEach(s => managers['products'].addStockRow(s));
                    else managers['products'].addStockRow();
                } else managers['products'].addStockRow();
                $('#productModal').modal('show');
            },
            getFormData: () => {
                const stockData = [];
                $('#product-stock-container .dynamic-row').each(function () {
                    const finalDim = $(this).find('.standard-dims').val() || $(this).find('.custom-dims').val().trim();
                    if (finalDim) stockData.push({ dimensions: finalDim, quantity: parseInt($(this).find('.quantity').val()) || 0 });
                });
                return { id: $('#product-id').val(), name: $('#product-name').val(), description: $('#product-description').val(), stock: stockData };
            },
            addStockRow: (stock = null) => {
                const options = AppConfig.STANDARD_CARPET_DIMENSIONS.map(dim => `<option value="${dim}">${dim}</option>`).join('');
                const row = $(`<div class="row dynamic-row align-items-end mb-2"><div class="col-md-5"><label class="form-label">ابعاد استاندارد</label><select class="form-select standard-dims"><option value="">انتخاب یا سفارشی وارد کنید</option>${options}</select></div><div class="col-md-4"><label class="form-label">ابعاد سفارشی</label><input type="text" class="form-control custom-dims" placeholder="مثال: 2.1x3.2"></div><div class="col-md-2"><label class="form-label">تعداد</label><input type="number" class="form-control quantity" value="0" min="0"></div><div class="col-md-1"><button type="button" class="btn btn-danger btn-sm remove-stock-btn"><i class="bi bi-x-lg"></i></button></div></div>`);
                if (stock) {
                    if (AppConfig.STANDARD_CARPET_DIMENSIONS.includes(stock.dimensions)) {
                        row.find('.standard-dims').val(stock.dimensions);
                        row.find('.custom-dims').prop('disabled', true);
                    } else {
                        row.find('.custom-dims').val(stock.dimensions);
                    }
                    row.find('.quantity').val(stock.quantity);
                }
                $('#product-stock-container').append(row);
            },
            refreshCacheKeys: ['products']
        });

        managers['expenses'] = createEntityManager({
            tableName: 'expenses', apiName: 'expense', nameFa: 'هزینه',
            modalId: '#expenseModal', formId: '#expense-form',
            prepareModal: (expense = null) => {
                $('#expense-form')[0].reset();
                const accountSelect = $('#expense-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
                App.getCache().accounts.forEach(acc => {
                    accountSelect.append(`<option value="${acc.id}">${acc.name}</option>`);
                });
                const categorySelect = $('#expense-category').empty().append('<option value="">-- انتخاب کنید --</option>');
                AppConfig.EXPENSE_CATEGORIES.forEach(cat => {
                    categorySelect.append(`<option value="${cat}">${cat}</option>`);
                });
                $('#expense-id').val(expense ? expense.id : '');

                if (expense && expense.date) {
                    const persianDateStr = UI.gregorianToPersian(expense.date);
                    $('#expense-date').val(persianDateStr);
                } else {
                    $('#expense-date').val(UI.today());
                }

                $('#expense-category').val(expense ? expense.category : '');
                $('#expense-amount').val(expense ? Number(expense.amount).toLocaleString('en-US') : '0');
                $('#expense-account-id').val(expense ? expense.account_id : '');
                $('#expense-description').val(expense ? expense.description : '');
                $('#expenseModal').modal('show');
            },
            getFormData: () => ({
                id: $('#expense-id').val(),
                category: $('#expense-category').val(),
                date: UI.toGregorian('#expense-date'),
                amount: $('#expense-amount').val().replace(/,/g, ''),
                account_id: $('#expense-account-id').val(),
                description: $('#expense-description').val()
            }),
            refreshTables: ['accounts', 'transactions']
        });

        managers['users'] = createEntityManager({
            tableName: 'users', apiName: 'user', nameFa: 'کاربر',
            renderTable: (data) => {
                const body = $('#users-table-body').empty();
                if (!data || !data.length) return;
                const currentUser = Auth.getCurrentUser();
                data.forEach(user => {
                    let actions = '';
                    if (user.username !== currentUser) {
                        actions = `<button class="btn btn-sm btn-warning btn-reset-password" data-id="${user.id}" data-username="${user.username}"><i class="bi bi-key"></i></button> 
                                   <button class="btn btn-sm btn-danger btn-delete-user" data-id="${user.id}" data-username="${user.username}"><i class="bi bi-trash"></i></button>`;
                    }
                    body.append(`<tr><td>${user.id}</td><td>${user.username}</td><td>${actions}</td></tr>`);
                });
            },
            handleDelete: (id, username) => {
                UI.confirmAction(`آیا از حذف کاربر «${username}» مطمئن هستید؟`, async (confirmed) => {
                    if (confirmed) {
                        const result = await Api.call('delete_user', { userId: id });
                        if (result?.success) {
                            managers['users'].load();
                        }
                    }
                });
            }
        });

        managers['activity_log'] = createEntityManager({
            tableName: 'activity_log',
            renderTable: (data) => {
                const body = $('#activity-log-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="4" class="text-center">هیچ فعالیتی ثبت نشده است.</td></tr>');
                data.forEach(log => body.append(`<tr><td>${log.username}</td><td>${log.action_type}</td><td>${log.description}</td><td>${new Date(log.timestamp).toLocaleString('fa-IR')}</td></tr>`));
            }
        });

        registerManager('accounts', AccountManager);
        registerManager('transactions', TransactionManager);
        registerManager('checks', CheckManager);
        registerManager('settings', SettingsManager);
        registerManager('partners', PartnerManager);

        for (const key in managers) {
            if (managers[key].init) managers[key].init();
        }

        $('body').on('click', '#add-product-stock-row', () => managers['products'].addStockRow());
        $('body').on('click', '.remove-stock-btn', function () { $(this).closest('.dynamic-row').remove(); });
        $('body').on('change', '.standard-dims', function () {
            const customInput = $(this).closest('.row').find('.custom-dims');
            if ($(this).val()) customInput.val('').prop('disabled', true);
            else customInput.prop('disabled', false);
        });
        $('body').on('click', '.btn-delete-user', function (e) {
            e.preventDefault();
            managers['users'].handleDelete($(this).data('id'), $(this).data('username'));
        });
    }

    function attachMainEvents() {
        $('body').on('click', '.pagination-container .page-link', function (e) {
            e.preventDefault();
            const page = $(this).data('page');
            const tableName = $(this).data('table');
            if (page && tableName) {
                const manager = getManager(tableName);
                const state = AppConfig.TABLE_STATES[tableName];
                if (state) {
                    state.currentPage = page;
                    if (manager) manager.load();
                    else InvoiceManager.load(tableName);
                }
            }
        });

        $('body').on('click', '.sortable-header', function () {
            const newSortBy = $(this).data('sort-by');
            const tableName = $(this).closest('table').attr('id').replace(/-table$/, '').replace(/-/g, '_');
            const manager = getManager(tableName);
            const state = AppConfig.TABLE_STATES[tableName];

            if (state) {
                if (state.sortBy === newSortBy) {
                    state.sortOrder = state.sortOrder === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    state.sortBy = newSortBy;
                    state.sortOrder = 'ASC';
                }
                state.currentPage = 1;

                if (manager) manager.load();
                else InvoiceManager.load(tableName);
            }
        });

        $('[data-bs-toggle="tab"]').on('shown.bs.tab', async function (e) {
            $('.modal.show').modal('hide');
            const target = $(e.target);
            const tabId = target.attr('id');

            if (tabId === 'dashboard-tab') {
                await Dashboard.load();
            } else if (tabId === 'consignment-tab') {
                await InvoiceManager.load('consignment_sales');
                await InvoiceManager.load('consignment_purchases');
            } else if (tabId === 'reports-tab') {
                populateEntitySelectForReports();
            } else if (tabId === 'settings-tab') {
                await getManager('settings').load();
                await getManager('partners').load();
                await getManager('users').load();
                await getManager('activity_log').load();
            } else if (target.data('table-name')) {
                const tableName = target.data('table-name');
                const manager = getManager(tableName);
                if (manager) {
                    await manager.load();
                } else if (tableName.includes('invoice')) {
                    await InvoiceManager.load(tableName);
                }
            }
        });

        $('#report-type').on('change', function () {
            const reportType = $(this).val();
            $('#report-person-controls').toggle(reportType === 'persons');
            $('#report-account-controls').toggle(reportType === 'accounts');
            $('#report-product-controls').toggle(reportType === 'inventory-ledger');
            $('#report-date-filter-start, #report-date-filter-end').toggle(!['inventory', 'inventory-value'].includes(reportType));
            $('#cogs-calc-wrapper').toggle(reportType === 'profit-loss');
        }).trigger('change');

        $('#generate-report-btn').on('click', function () {
            const reportType = $('#report-type').val();
            const container = $('#report-results-container');
            ReportGenerator.generate(reportType, container);
        });

        $('#export-report-btn').on('click', function () {
            ReportGenerator.exportCsv();
        });

        $('body').on('hidden.bs.modal', '.modal', function () {
            if (document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
        });

        $(document).on('show.bs.modal', '.modal', function () {
            const openModals = $('.modal.show');
            if (openModals.length > 0) {
                $(openModals[openModals.length - 1]).addClass('modal-underlay');
            }
        });
        $(document).on('hidden.bs.modal', '.modal', function () {
            $('.modal.modal-underlay').removeClass('modal-underlay');
        });

        $('body').on('click', '.report-link', async function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const type = $(this).data('type');

            if (!id || !type) return;

            const openModal = $('.modal.show');
            if (openModal.length > 0) {
                openModal.modal('hide');
                await new Promise(resolve => setTimeout(resolve, 300));
            }

            UI.showLoader();
            let entityData;

            if (type === 'check') {
                entityData = await Api.call('get_entity_by_id', { entityType: 'payment', id: id, by: 'checkId' });
            } else {
                entityData = await Api.call('get_entity_by_id', { entityType: type, id: id });
            }
            UI.hideLoader();

            if (entityData) {
                if (type === 'salesInvoice') {
                    InvoiceManager.prepareInvoiceModal('sales', entityData);
                } else if (type === 'purchaseInvoice') {
                    InvoiceManager.prepareInvoiceModal('purchase', entityData);
                } else if (type === 'expense') {
                    App.getManager('expenses').prepareModal(entityData);
                } else if (type === 'payment' || type === 'check') {
                    App.getManager('transactions').prepareTransactionModal(entityData);
                }
            }
        });

        $('body').on('click', '#dashboard .clickable-row', function () {
            const row = $(this);
            const reportType = row.data('report-type');

            if (reportType === 'persons') {
                const reportTabButton = document.querySelector('#mainTab button[data-bs-target="#reports"]');
                if (!reportTabButton) return;
                new bootstrap.Tab(reportTabButton).show();

                const personId = row.data('person-id');
                const personType = row.data('person-type');

                $('#report-type').val('persons').trigger('change');
                setTimeout(() => {
                    $('#report-person-select').val(`${personType}-${personId}`).trigger('change');
                    $('#generate-report-btn').click();
                }, 200);

            } else if (reportType === 'checks') {
                const checkNumber = row.data('check-number');
                const checksTabButton = document.querySelector('#mainTab button[data-bs-target="#checks"]');
                if (!checksTabButton) return;

                new bootstrap.Tab(checksTabButton).show();

                if (checkNumber) {
                    setTimeout(() => {
                        const searchInput = $('#check-search');
                        searchInput.val(checkNumber);
                        searchInput.trigger('input');
                    }, 250);
                }
            }
        });

        $('body').on('click', '.btn-clear-check', function () {
            const checkId = $(this).data('id');
            const modal = $('#cashCheckModal');
            modal.find('.modal-title').text('ثبت پاس شدن چک');
            modal.find('p').text('چک از کدام حساب برداشت (پاس) شد؟');
            $('#cash-check-id').val(checkId);
            $('#cash-check-form').data('action', 'clear_payable_check');

            const accountSelect = $('#cash-check-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
            App.getCache().accounts.forEach(acc => {
                accountSelect.append(`<option value="${acc.id}">${acc.name}</option>`);
            });

            modal.modal('show');
        });

        $('body').on('click', '.btn-cash-check', function () {
            const checkId = $(this).data('id');
            const modal = $('#cashCheckModal');
            modal.find('.modal-title').text('وصول چک');
            modal.find('p').text('چک به کدام حساب واریز شود؟');
            $('#cash-check-id').val(checkId);
            $('#cash-check-form').data('action', 'cash_check');

            const accountSelect = $('#cash-check-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
            App.getCache().accounts.forEach(acc => {
                accountSelect.append(`<option value="${acc.id}">${acc.name}</option>`);
            });

            modal.modal('show');
        });

        $('#cash-check-form').on('submit', async function (e) {
            e.preventDefault();
            const action = $(this).data('action');
            const data = {
                checkId: $('#cash-check-id').val(),
                accountId: $('#cash-check-account-id').val()
            };

            UI.showLoader();
            const result = await Api.call(action, data);
            UI.hideLoader();

            if (result?.success) {
                $('#cashCheckModal').modal('hide');
                UI.showSuccess('عملیات با موفقیت ثبت شد.');
                await App.updateCache(['checks', 'accounts']);
                App.getManager('checks').load();
                App.getManager('accounts').load();
                App.getManager('transactions').load();
            }
        });
    }

    function initMainApp(sessionData) {
        appState.currentUser = sessionData.username;
        appState.currentEntityId = sessionData.current_entity_id;
        appState.businessEntities = sessionData.business_entities;

        updateEntitySwitcherUI();

        UI.initializeDatepickers();
        UI.initNumericFormatting();

        $('#report-start-date').val(UI.firstDayOfYear());
        $('#report-end-date').val(UI.today());

        initEntityManagers();
        InvoiceManager.init();
        AccountManager.init();
        TransactionManager.init();
        CheckManager.init();
        SettingsManager.init();
        ReportGenerator.init();

        $('#add-sales-invoice-btn').on('click', () => InvoiceManager.prepareInvoiceModal('sales'));
        $('#add-purchase-invoice-btn').on('click', () => InvoiceManager.prepareInvoiceModal('purchase'));

        fetchInitialCache().then(() => {
            Dashboard.load();
            updateNotifications();
            notificationInterval = setInterval(updateNotifications, 300000);
        });
    }

    function init() {
        $(document).ready(function () {
            attachMainEvents();
            Auth.init();
        });
    }

    return {
        init,
        initMainApp,
        fetchInitialCache,
        updateCache,
        getManager,
        registerManager,
        getCache,
        getAppState,
    };

})();

App.init();